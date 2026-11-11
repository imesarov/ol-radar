<?php
// Traži sintaksu noviju od PHP 7.4 u datotekama dodatka (tokenizer).
$bad = array( 'T_MATCH', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ATTRIBUTE', 'T_READONLY', 'T_ENUM', 'T_NAME_QUALIFIED' );
$funcs = array( 'str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type', 'fdiv' );
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $argv[1] ) );
$n = 0;
foreach ( $it as $f ) {
	if ( substr( $f, -4 ) !== '.php' ) { continue; }
	$toks = token_get_all( file_get_contents( $f ) );
	foreach ( $toks as $i => $t ) {
		if ( is_array( $t ) ) {
			$name = token_name( $t[0] );
			if ( in_array( $name, $bad, true ) && ! ( 'T_NAME_QUALIFIED' === $name ) ) { echo "$f:{$t[2]} $name\n"; $n++; }
			if ( 'T_STRING' === $name && in_array( strtolower( $t[1] ), $funcs, true ) ) { echo "$f:{$t[2]} funkcija {$t[1]}\n"; $n++; }
			// imenovani argumenti: T_STRING ':' unutar poziva (grubo)
		}
		// zarez prije zatvorene zagrade poziva funkcije
		if ( ')' === $t ) {
			for ( $j = $i - 1; $j > 0 && is_array( $toks[ $j ] ) && T_WHITESPACE === $toks[ $j ][0]; $j-- ) {}
			if ( ',' === $toks[ $j ] ) { echo "$f: zarez prije ')' (token $i)\n"; $n++; }
		}
	}
}
echo "problema: $n\n";
