<?php
/**
 * Simple PO to MO compiler for Tierza.
 */

declare(strict_types=1);

function compile_po_to_mo( string $po_path, string $mo_path ): void {
	$po_content = file_get_contents( $po_path );
	if ( false === $po_content ) {
		throw new RuntimeException( "Cannot read PO file: $po_path" );
	}

	$entries = [];
	$lines   = explode( "\n", $po_content );

	$current_msgid = null;
	$current_msgstr = null;
	$state = null;

	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			if ( null !== $current_msgid && null !== $current_msgstr ) {
				$entries[ $current_msgid ] = $current_msgstr;
				$current_msgid = null;
				$current_msgstr = null;
				$state = null;
			}
			continue;
		}

		if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
			if ( null !== $current_msgid && null !== $current_msgstr ) {
				$entries[ $current_msgid ] = $current_msgstr;
			}
			$current_msgid = stripcslashes( $m[1] );
			$current_msgstr = null;
			$state = 'msgid';
		} elseif ( preg_match( '/^msgstr\s+"(.*)"$/', $line, $m ) ) {
			$current_msgstr = stripcslashes( $m[1] );
			$state = 'msgstr';
		} elseif ( preg_match( '/^"(.*)"$/', $line, $m ) ) {
			if ( 'msgid' === $state ) {
				$current_msgid .= stripcslashes( $m[1] );
			} elseif ( 'msgstr' === $state ) {
				$current_msgstr .= stripcslashes( $m[1] );
			}
		}
	}

	if ( null !== $current_msgid && null !== $current_msgstr ) {
		$entries[ $current_msgid ] = $current_msgstr;
	}

	// Build MO binary.
	ksort( $entries );
	$count = count( $entries );

	$originals_table = '';
	$translations_table = '';
	$originals_data = '';
	$translations_data = '';

	$data_offset = 28 + ( $count * 8 * 2 );

	// Calculate data offsets.
	$current_orig_offset = $data_offset;
	$orig_lengths = [];
	$orig_offsets = [];

	foreach ( array_keys( $entries ) as $orig ) {
		$len = strlen( $orig );
		$orig_lengths[] = $len;
		$orig_offsets[] = $current_orig_offset;
		$current_orig_offset += $len + 1; // null-terminated
	}

	$current_trans_offset = $current_orig_offset;
	$trans_lengths = [];
	$trans_offsets = [];

	foreach ( array_values( $entries ) as $trans ) {
		$len = strlen( $trans );
		$trans_lengths[] = $len;
		$trans_offsets[] = $current_trans_offset;
		$current_trans_offset += $len + 1;
	}

	// Pack headers and tables.
	$mo = pack(
		'V*',
		0x950412de, // Magic number
		0,          // Format revision
		$count,     // Number of strings
		28,         // Offset of original string table
		28 + ( $count * 8 ), // Offset of translation string table
		0,          // Size of hash table
		0           // Offset of hash table
	);

	for ( $i = 0; $i < $count; $i++ ) {
		$mo .= pack( 'V2', $orig_lengths[ $i ], $orig_offsets[ $i ] );
	}

	for ( $i = 0; $i < $count; $i++ ) {
		$mo .= pack( 'V2', $trans_lengths[ $i ], $trans_offsets[ $i ] );
	}

	foreach ( array_keys( $entries ) as $orig ) {
		$mo .= $orig . "\0";
	}

	foreach ( array_values( $entries ) as $trans ) {
		$mo .= $trans . "\0";
	}

	file_put_contents( $mo_path, $mo );
	echo "Compiled " . count( $entries ) . " entries into $mo_path\n";
}

compile_po_to_mo(
	__DIR__ . '/../languages/tierza-fa_IR.po',
	__DIR__ . '/../languages/tierza-fa_IR.mo'
);
