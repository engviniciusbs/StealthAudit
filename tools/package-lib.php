<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Shared by tools/package.php and tools/verify-package.php (build tooling, never shipped).
//
// The product's own PHP is NOT given to PHP-Scoper (it would prefix WordPress symbols and our
// STEALTHAUDIT_* constants). Its few references to third-party code are rewritten here with an
// exact token pass: imports ("use X\Y;") and fully qualified names whose namespace is a scoped
// vendor namespace gain the StealthAuditVendor\ prefix; nothing else changes, so a packaged
// src/ file differs from the repository only on those lines. Anything this pass cannot rewrite
// safely (a relative vendor name, a group import, a vendor class name inside a string) stops
// the build instead of shipping a reference that would resolve to someone else's copy.

const STEALTHAUDIT_PACKAGE_PREFIX = 'StealthAuditVendor';

/**
 * Whether $name (no leading backslash) belongs to one of the vendor namespaces/functions.
 *
 * @param string       $name  Name as written, without leading backslash.
 * @param list<string> $roots Vendor namespaces ("GeoIp2", "Psr\Clock") and global function names.
 */
function stealthaudit_package_is_vendor( string $name, array $roots ): bool {
	foreach ( $roots as $root ) {
		if ( 0 === strcasecmp( $name, $root ) || 0 === strncasecmp( $name, $root . '\\', strlen( $root ) + 1 ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Rewrite vendor references in one product file, or (scan mode) list the ones left unprefixed.
 *
 * @param string       $code   PHP source.
 * @param list<string> $roots  Vendor namespaces and global function names (unprefixed).
 * @param bool         $scan   True: change nothing; return problems instead.
 * @param bool         $vendor Third-party code: prefixed references to symbols that are not
 *                             shipped (optional dependencies such as psr/log) are legitimate there.
 * @return array{code:string,rewritten:int,problems:list<string>}
 */
function stealthaudit_package_prefix_source( string $code, array $roots, bool $scan = false, bool $vendor = false ): array {
	$roots     = array_values( array_filter( $roots, static fn ( string $root ): bool => '' !== $root ) );
	$prefix    = STEALTHAUDIT_PACKAGE_PREFIX;
	$tokens    = PhpToken::tokenize( $code );
	$depth     = 0;
	$import    = false;
	$rewritten = 0;
	$problems  = array();
	$out       = '';
	foreach ( $tokens as $index => $token ) {
		$text = $token->text;
		if ( $token->is( array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) || '{' === $text ) {
			++$depth;
		} elseif ( '}' === $text ) {
			--$depth;
		} elseif ( $token->is( T_NAMESPACE ) ) {
			// Braced namespaces would put imports at depth 1; the product does not use them.
			// (namespace\Foo is a single T_NAME_RELATIVE token, so this is always a declaration.)
			for ( $next = $index + 1; isset( $tokens[ $next ] ) && ! in_array( $tokens[ $next ]->text, array( ';', '{' ), true ); ++$next ) {
				continue;
			}
			if ( isset( $tokens[ $next ] ) && '{' === $tokens[ $next ]->text ) {
				$problems[] = 'braced namespace declaration is not supported';
			}
		} elseif ( 0 === $depth && $token->is( T_USE ) ) {
			$import = true;
		} elseif ( $import && ( ';' === $text ) ) {
			$import = false;
		} elseif ( $token->is( array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING ) ) ) {
			$qualified = $token->is( T_NAME_FULLY_QUALIFIED );
			$name      = ltrim( $text, '\\' );
			$prefixed  = 0 === strncmp( $name, $prefix . '\\', strlen( $prefix ) + 1 );
			if ( $prefixed ) {
				if ( ! $vendor && ! stealthaudit_package_is_vendor( substr( $name, strlen( $prefix ) + 1 ), $roots ) ) {
					$problems[] = "line {$token->line}: {$name} is prefixed but is not a vendor symbol";
				}
			} elseif ( stealthaudit_package_is_vendor( $name, $roots ) ) {
				$next = $tokens[ $index + 1 ] ?? null;
				if ( $token->is( T_STRING ) ) {
					// A bare word: only a vendor *function* name matters, and only when it is imported or
					// called; a group import ("use Foo\{A,B}") starts with a root followed by "\".
					$after  = $index + 1;
					$before = $index - 1;
					while ( isset( $tokens[ $after ] ) && $tokens[ $after ]->is( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ) ) ) {
						++$after;
					}
					while ( isset( $tokens[ $before ] ) && $tokens[ $before ]->is( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ) ) ) {
						--$before;
					}
					$member = isset( $tokens[ $before ] ) && $tokens[ $before ]->is( array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NEW ) );
					if ( null !== $next && $next->is( T_NS_SEPARATOR ) ) {
						$problems[] = "line {$token->line}: group import of {$name} is not supported";
					} elseif ( $import ) {
						$problems[] = "line {$token->line}: unqualified import of {$name}";
					} elseif ( ! $member && isset( $tokens[ $after ] ) && '(' === $tokens[ $after ]->text && ! ctype_upper( $name[0] ) ) {
						// An unqualified call to a scoped vendor function falls back to the GLOBAL one.
						$problems[] = "line {$token->line}: unqualified call to vendor function {$name}()";
					}
				} elseif ( $import || $qualified ) {
					if ( $scan ) {
						$problems[] = "line {$token->line}: {$name} is not prefixed";
					} else {
						$text = ( $qualified ? '\\' : '' ) . $prefix . '\\' . $name;
						++$rewritten;
					}
				} else {
					// Relative to the file's namespace: StealthAudit\...\GeoIp2\X, never the vendor class.
					$problems[] = "line {$token->line}: relative name {$name} cannot be scoped safely";
				}
			}
		} elseif ( $token->is( array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ) ) ) {
			$plain = str_replace( '\\\\', '\\', $text );
			foreach ( $roots as $root ) {
				if ( str_contains( $root, '\\' ) || ctype_upper( $root[0] ) ) {
					// Separator then a capital ("GeoIp2\Model\City"), not an escape ("BaconQrCode\n").
					if ( preg_match( '/(?<![A-Za-z0-9_\\\\])' . preg_quote( $root, '/' ) . '\\\\[A-Z]/', $plain ) && ! str_contains( $plain, $prefix . '\\' . $root ) ) {
						$problems[] = "line {$token->line}: string mentions vendor namespace {$root}";
					}
				}
			}
		}
		$out .= $text;
	}
	return array(
		'code'      => $scan ? $code : $out,
		'rewritten' => $rewritten,
		'problems'  => $problems,
	);
}

/**
 * Composer's per-file identifier for a "files" autoload entry, made unique to this package.
 *
 * Composer marks loaded "files" in $GLOBALS['__composer_autoload_files'][md5(name:path)], shared
 * by every Composer autoloader in the process. Another plugin that loaded the same package first
 * would make Composer skip OUR scoped copy (which declares StealthAuditVendor\... functions).
 *
 * @param string $package Package name.
 * @param string $path    Path inside the package.
 */
function stealthaudit_package_files_identifier( string $package, string $path ): string {
	return hash( 'md5', STEALTHAUDIT_PACKAGE_PREFIX . ':' . hash( 'md5', $package . ':' . $path ) );
}

/**
 * Recursively list regular files under $dir as sorted relative paths ("/" separators).
 *
 * @param string $dir Directory.
 * @return list<string>
 */
function stealthaudit_package_files( string $dir ): array {
	$files = array();
	$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iter as $file ) {
		if ( $file->isLink() ) {
			throw new RuntimeException( 'Symbolic link in package tree: ' . $file->getPathname() );
		}
		if ( $file->isFile() ) {
			$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
		}
	}
	sort( $files, SORT_STRING );
	return $files;
}

/**
 * Remove a directory tree (temporary build directories only).
 *
 * @param string $dir Directory.
 */
function stealthaudit_package_remove( string $dir ): void {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iter as $file ) {
		$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $dir );
}

/**
 * The version from a loader header.
 *
 * @param string $loader Loader source.
 */
function stealthaudit_package_loader_version( string $loader ): ?string {
	return preg_match( '/^ \* Version: (\S+)$/m', $loader, $match ) ? $match[1] : null;
}
