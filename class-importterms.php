<?php
/**
 * Imports Post data from CSV into WordPress
 *
 * ## EXAMPLES
 *
 *     # Import data from books.csv
 *     $ wp csv import --type=post_type --name=books books.csv
 *     Success: Imported 102 records
 *
 * @package wp-cli
 */

/**
 * Class Import CSV
 */
class ImportTerms extends WP_CLI_Command {

	/**
	 * POST fields
	 *
	 * @var array
	 */

	const TERM_FIELDS = array(
		'name',
		'alias_of',
		'description',
		'parent',
		'parent_id',
		'slug',
	);

	const IMAGE_TYPES = array(
		IMAGETYPE_GIF  => 'gif',
		IMAGETYPE_JPEG => 'jpeg',
		IMAGETYPE_PNG  => 'png',
	);

	/**
	 * Parsed import file header
	 *
	 * @var array
	 */
	public $headers = array();

	/**
	 * Import file handle
	 *
	 * @var handle
	 */
	public $file = null;

	// phpcs:disable
	private function logInfo( $var ) {
		if ( is_array( $var ) || is_object( $var ) ) {
			error_log( print_r( $var, true ) );
		} else {
			error_log( $var );
		}
	}
	// phpcs:enable

	/**
	 * Constructor
	 *
	 * @param CSVCommands $csv - CSVCommands object.
	 */
	public function __construct( CSVCommands $csv ) {
		$this->csv = $csv;
	}

	/**
	 * Import CSV data
	 *
	 * @return void
	 */
	public function import() {
		$this->parse_headers();
		if ( $this->csv->dry_run || $this->csv->verbose ) {
			WP_CLI\Utils\format_items(
				'table',
				array_filter(
					$this->headers,
					function( $v ) {
						return null !== $v;
					}
				),
				array_keys( $this->headers[0] )
			);
			WP_CLI::success( 'The mapping JSON file is formatted properly.' );
		}

		$num     = 0;
		$failed  = 0;
		$success = 0;
		while ( ( $row = fgetcsv( $this->file, 0, $this->csv->csv_delim, $this->csv->csv_enclosure, $this->csv->csv_escape ) ) !== false ) { //phpcs:ignore
			$num++;
			if ( $this->csv->csv_strict && ( count( $this->headers ) !== count( $row ) ) ) {
				WP_CLI::warning(
					sprintf(
						// translators: 1: Row number 2: Expected number of columns 3: Actual number of columns.
						__( 'Row #%1$d: Expected %2$d columns. Actual: %3$d. Skipping.', 'wp-cli-csv-commands' ),
						$num,
						count( $this->headers ),
						count( $row )
					)
				);
				$failed++;
				continue;
			}
			$data = array();
			foreach ( $this->headers as $k => $v ) {
				if ( null === $v ) {
					continue;
				}
				if ( isset( $v['delimiter'] ) ) {
					$parts  = explode( $v['delimiter'], $row[ $k ] );
					$values = array();
					foreach ( $parts as $part ) {
						$values[] = $this->sanitize_using_func( $v['sanitize'], $part );
					}
					$current_value = $values;
				} else {
					$current_value = $this->sanitize_using_func( $v['sanitize'], $row[ $k ] );
				}
				$old_value = isset( $data[ $v['type'] ][ $v['name'] ] ) ? $data[ $v['type'] ][ $v['name'] ] : null;
				if ( null !== $old_value ) {
					if ( ! is_array( $old_value ) ) {
						$old_value = array( $old_value );
					}
					if ( ! is_array( $current_value ) ) {
						$current_value = array( $current_value );
					}
					$current_value = array_merge( $old_value, $current_value );
				}
				$data[ $v['type'] ][ $v['name'] ] = $current_value;
			}
			if ( $this->csv->dry_run || $this->write_row( $data, $num ) ) {
				$success++;
			} else {
				$failed++;
			}
		}
		if ( 0 === $failed ) {
			WP_CLI::success(
				sprintf(
					// translators: 1: Number of records written.
					__( 'Wrote %1$d records.', 'wp-cli-csv-commands' ),
					$success
				)
			);
		} else {
			WP_CLI::warning(
				sprintf(
					// translators: 1: Number of records written 2: Total number of records 3: Failed.
					__( 'Wrote %1$d/%2$d records. Failed: %3$d.', 'wp-cli-csv-commands' ),
					$success,
					$num,
					$failed
				)
			);
		}
	}

	/**
	 * Write a single row of data into WordPress database.
	 *
	 * @param array $data - The data extracted from a row.
	 * @param int   $row_num - The current row number.
	 * @return boolean - true on successfully updating db.
	 */
	private function write_row( $data, $row_num ) {
		if ( ! isset( $data['term'] ) ) {
			WP_CLI::warning(
				sprintf(
					// translators: 1: Row number.
					'Row #%d: No term data.',
					$row_num
				)
			);
			return false;
		}
		$term_id = $this->insert_term( $data['term'], $row_num );
		if ( ! $term_id ) {
			return false;
		}
		$saved_data = array();

		$saved_data['term_id'] = $term_id;
		$saved_data['term']    = $data['term'];

		if ( isset( $data['meta'] ) ) {
			$saved_data['meta'] = $this->save_row_meta( $term_id, $data['meta'], $row_num );
		}

		if ( isset( $data['thumbnail'] ) ) {
			$term_object              = get_term( $term_id );
			$saved_data['thumbnails'] = $this->save_row_thumbnails( $term_id, $data['thumbnail'], $term_object, $row_num );
		}
		/**
		 * Perform an action post-update.
		 *
		 * This action runs after all data is updated. The saved data and the post type are passed as arguments.
		 *
		 * @param array $saved_data - Data containing post, meta and thumbnails
		 * @param string $taxonomy - The taxonomy being used
		 */
		do_action( 'csv_commands_write_row', $saved_data, $this->csv->taxonomy );
		return true;
	}

	/**
	 * Fix missing entries for mappings
	 *
	 * @param array $mappings - mappings to which missing keys to add.
	 * @return void
	 */
	private function fix_missing( &$mappings ) {
		$keys     = array_keys( $mappings );
		$defaults = array(
			'type'      => null,
			'sanitize'  => array(),
			'name'      => null,
			'delimiter' => null,
		);
		foreach ( $keys as $key ) {
			$mappings[ $key ] = wp_parse_args( $mappings[ $key ], $defaults );
			if ( ! is_array( $mappings[ $key ]['sanitize'] ) ) {
				$mappings[ $key ]['sanitize'] = array( $mappings[ $key ]['sanitize'] );
			}
		}
	}

	/**
	 * Read mapping information from mappings JSON file
	 *
	 * @return array - an association array of json
	 */
	private function get_mappings() {
		$mappings = false;
		if ( ! empty( $this->csv->mapping_file ) ) {
			$data = @file_get_contents( $this->csv->mapping_file ); // phpcs:ignore
			if ( false === $data ) {
				$error = error_get_last();
				WP_CLI::error(
					sprintf(
						// translators: 1: Mapping file name 2: Error message.
						__( '%1$s: Unable to read mapping file (%2$s).', 'wp-cli-csv-commands' ),
						$this->csv->mapping_file,
						$error['message']
					)
				);
			}
			$mappings = json_decode( $data, true );
			if ( ! $mappings ) {
				WP_CLI::error(
					sprintf(
						// translators: 1: Mapping file name 2: Error message.
						__( '%1$s: Unable to read JSON data from mapping file (%2$s).', 'wp-cli-csv-commands' ),
						$this->csv->mapping_file,
						json_last_error_msg()
					)
				);
			}
		}
		/**
		 * Filters the CSV mappings.
		 *
		 * This filter runs after the JSON data is read but before validation. Returning false from the filter
		 * aborts the process.
		 *
		 * @param array $mappings - the mappings read from the JSON file or false if file is not given
		 */
		$mappings = apply_filters( 'csv_commands_mappings', $mappings, $this->csv->post_type );
		if ( ! $mappings ) {
			WP_CLI::error( 'Could not get mapping data.' );
		}
		$messages = array();

		$this->fix_missing( $mappings );

		foreach ( $mappings as $head => $mapping ) {

			// type, sanitize, name, delimiter.
			extract( $mapping ); // phpcs:ignore

			if ( null !== $this->csv->taxonomy && ! in_array( $type, array( 'meta', 'term', 'thumbnail' ), true ) ) {
				$messages[] = sprintf(
					// translators: 1: CSV header value 2: Type provided.
					__( '%1$s: %2$s is an unsupported field type. Possible types are meta, taxonomy, thumbnail!', 'wp-cli-csv-commands' ),
					$head,
					$type
				);
			}

			if ( 'term' === $type && null !== $delimiter ) {
				$messages[] = sprintf(
					// translators: 1: CSV header value.
					__( '%1$s: Term type does not support delimiter.', 'wp-cli-csv-commands' ),
					$head
				);
			}

			if ( 'term' === $type && ! $this->is_valid_term_field( $name ) ) {
				$messages[] = sprintf(
					// translators: 1: CSV header value 2: Type provided.
					__( '%1$s: Invalid term field %2$s used.', 'wp-cli-csv-commands' ),
					$head,
					$name
				);
			}

			foreach ( $sanitize as $f ) {
				if ( ! function_exists( $f ) ) {
					$messages[] = sprintf(
						// translators: 1: CSV header value 2: Sanitize function given.
						__( '%1$s: %2$s is an undefined function. ensure your sanitization functions exist.', 'wp-cli-csv-commands' ),
						$head,
						$f
					);
				}
			}
		}

		$this->check_duplicates( $mappings, $messages );

		if ( 0 !== count( $messages ) ) {
			WP_CLI::error_multi_line( $messages );
			WP_CLI::error( __( 'Errors in the mapping JSON.', 'wp-cli-csv-commands' ) );
		}

		return $mappings;
	}

	/**
	 * Check whether given field_name is a valid post field
	 *
	 * @param string $field_name - name of the field.
	 * @return boolean
	 */
	private function is_valid_term_field( $field_name ) {
		return in_array( $field_name, self::TERM_FIELDS, true );
	}

	/**
	 * Check for duplicate entries in the given mappings
	 *
	 * @param array  $mappings - the mappings.
	 * @param [type] $messages - error messages.
	 * @return void
	 */
	private function check_duplicates( $mappings, &$messages ) {
		$names              = array_map(
			function( $v ) {
				return $v['type'] . ':' . $v['name'];
			},
			$mappings
		);
		$names_count        = array_count_values( $names );
		$dupe_names         = array_filter(
			$names_count,
			function( $v, $k ) {
				return ! $this->is_multiple_values_supported( $k ) && $v > 1;
			},
			ARRAY_FILTER_USE_BOTH
		);
		$duplicate_mappings = array();
		foreach ( $mappings as $header => $mapping ) {
			$key = $mapping['type'] . ':' . $mapping['name'];
			if ( isset( $dupe_names[ $key ] ) ) {
				$entry = $duplicate_mappings[ $key ];
				if ( null === $entry ) {
					$duplicate_mappings[ $key ] = array();
				}
				$duplicate_mappings[ $key ][ $header ] = $mapping;
			}
		}
		foreach ( $duplicate_mappings as $key => $dup ) {
			[ $type, $name ] = explode( ':', $key ); // phpcs:ignore
			$dup_headers     = array_keys( $dup );
			$messages[]      = sprintf(
				// translators: 1: CSV header value 2: name of the field 3: List of other entries having the same value.
				__( '%1$s: Duplicate entries found for `%2$s` for %3$s. Other entries: %3$s.', 'wp-cli-csv-commands' ),
				$dup_headers[0],
				$type,
				implode( ',', array_slice( $dup_headers, 1 ) )
			);
		}
	}
	/**
	 * Given a type+name combo find whether multiple values are supported
	 *
	 * @param string $v - The type/field name.
	 * @return boolean
	 */
	private function is_multiple_values_supported( $v ) {
		[ $type, $name ] = explode( ':', $v ); // phpcs:ignore
		return 'term' !== $type;
	}

	/**
	 * Read header row from CSV file
	 *
	 * @return array
	 */
	private function get_header_row() {
		$this->open_import_file();
		$header_row = fgetcsv( $this->file, 0, $this->csv->csv_delim, $this->csv->csv_enclosure, $this->csv->csv_escape );
		if ( ! $header_row ) {
			WP_CLI::error(
				sprintf(
					// translators: 1: Name of the file.
					__( 'Unable to read file %1$s. Check formatting.', 'wp-cli-csv-commands' ),
					$this->csv->filename
				)
			);
		}
		$nparts = count( $header_row );
		while ( 1 === $nparts && null === $header[0] ) { // Empty lines.
			$header_row = fgetcsv( $this->file, 0, $this->csv->csv_delim, $this->csv->csv_enclosure, $this->csv->csv_escape );
			$nparts     = count( $header_row );
			if ( ! $header_row ) {
				WP_CLI::error(
					sprintf(
						// translators: 1: Name of the file.
						__( 'Unable to read file %1$s. Check formatting.', 'wp-cli-csv-commands' ),
						$this->csv->filename
					)
				);
			}
		}
		if ( $this->csv->csv_header ) {
			return $header_row;
		}
		$temp_header = array();
		$nheader     = count( $header_row );
		for ( $i = 0; $i < $nheader; $i++ ) {
			$temp_header[] = 'C' . ( $i + 1 );
		}
		rewind( $this->file );
		return $temp_header;
	}

	/**
	 * Parse headers
	 *
	 * @access private
	 * @return void
	 */
	private function parse_headers() {
		$header_row = $this->get_header_row();
		$mappings   = $this->get_mappings();
		foreach ( $header_row as $header ) {
			$this->headers[] = isset( $mappings[ $header ] ) ? $mappings[ $header ] : null;
		}
	}

	/**
	 * Open import file
	 *
	 * @access private
	 * @return void
	 */
	private function open_import_file() {
		if ( ! file_exists( $this->csv->filename ) ) {
			WP_CLI::error(
				sprintf(
					// translators: 1: Name of the file.
					__( 'File %1$s does not exist.', 'wp-cli-csv-commands' ),
					$this->csv->filename
				)
			);
		}
		// phpcs:ignore
		$this->file = fopen( $this->csv->filename, 'r' );
		if ( ! $this->file ) {
			WP_CLI::error(
				sprintf(
					// translators: 1: Name of the file.
					__( 'Unable to open file %1$s. Check permissions.', 'wp-cli-csv-commands' ),
					$this->csv->filename
				)
			);
		}
	}

	/**
	 * Insert row term data
	 *
	 * @access private
	 * @param array   $term_data term data to be saved.
	 * @param integer $row_num currently processed row.
	 * @return bool true on success
	 */
	private function insert_term( $term_data, $row_num ) {
		if ( $this->csv->verbose ) {
			WP_CLI::line( 'Inserting a term...' );
			WP_CLI\Utils\format_items(
				'table',
				array(
					array_map(
						function( $v ) {
							return wp_json_encode( $v ); },
						$term_data
					),
				),
				array_keys( $term_data )
			);
		}
		if ( isset( $term_data['name'] ) ) {
			$name = $term_data['name'];
		}
		$args = array();
		if ( isset( $term_data['description'] ) ) {
			$args['description'] = $term_data['description'];
		}
		if ( isset( $term_data['alias_of'] ) ) {
			$args['alias_of'] = $term_data['alias_of'];
		}
		if ( isset( $term_data['slug'] ) ) {
			$args['slug'] = $term_data['slug'];
		}
		if ( isset( $term_data['parent_id'] ) ) {
			$args['parent'] = intval( $term_data['parent_id'] );
		}
		$r = term_exists( $name, $this->csv->taxonomy );
		if ( null !== $r ) {
			$term_id = $r['term_id'];
		}
		if ( isset( $term_id ) ) {
			$r = wp_update_term( $term_id, $this->csv->taxonomy, $args );
		} else {
			$r = wp_insert_term( $name, $this->csv->taxonomy, $args );
		}
		if ( ! is_wp_error( $r ) ) {
			return $r['term_id'];
		}
		WP_CLI::warning(
			sprintf(
				// translators: 1: Row number.
				__( 'Row #%d: Unable to insert the term (errors below).', 'wp-cli-csv-commands' ),
				$row_num
			)
		);
		foreach ( $r->errors as $error ) {
			WP_CLI::warning( $error[0] );
		}
		return false;
	}

	/**
	 * Save row metadata
	 *
	 * @access private
	 * @param integer $term_id term id to set meta data.
	 * @param array   $meta_data meta data to be saved.
	 * @param integer $row_num row number to report errors.
	 *
	 * @return array Metadata as inserted.
	 */
	private function save_row_meta( $term_id, $meta_data, $row_num ) {

		if ( $this->csv->verbose ) {
			WP_CLI::line( 'Inserting meta...' );
			WP_CLI\Utils\format_items(
				'table',
				array( $meta_data ),
				array_keys( $meta_data )
			);
		}

		$meta = array();
		foreach ( $meta_data as $k => $value ) {
			$prev_value = get_term_meta( $term_id, $k, true );
			if ( $prev_value === $value ) {
				$meta[ $k ] = $value;
				continue;
			}
			$r = update_term_meta( $term_id, $k, $value, );
			if ( is_int( $r ) || true === $r ) {
				$meta[ $k ] = $value;
				continue;
			}
			WP_CLI::warning(
				sprintf(
					// translators: 1: Row number.
					__( 'Row #%1$d: Unable to update term meta for %2$s with value %3$s (errors below).', 'wp-cli-csv-commands' ),
					$row_num,
					$k,
					$value
				)
			);
			if ( is_wp_error( $r ) ) {
				foreach ( $r->errors as $error ) {
					WP_CLI::warning( $error[0] );
				}
			}
		}
		return $meta;
	}

	/**
	 * Save row thumbnails
	 *
	 * @access private
	 * @param integer $term_id post id to attach thumbnails to.
	 * @param array   $thumbnails thumbnails to be imported.
	 * @param WP_Term $term_object - The term object.
	 * @param integer $row_num current row number.
	 *
	 * @return array Thumbnail IDs as inserted
	 */
	private function save_row_thumbnails( $term_id, $thumbnails, $term_object, $row_num ) {
		if ( $this->csv->verbose ) {
			WP_CLI::line( 'Inserting thumbnails...' );
			WP_CLI\Utils\format_items(
				'table',
				array( $thumbnails ),
				array_keys( $thumbnails )
			);
		}
		$thumbs = array();

		foreach ( $thumbnails as $k => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$image = $this->csv->thumbnail_base_url . $value;

			/*
			* This is very similar to media_sideload_image(),
			* but adds some additional checks and data,
			* as well as multi featured image sizes
			*/

			// Download featured image from url to temp location.
			$tmp_image = download_url( $image );

			// If error storing temporarily, unlink.
			if ( is_wp_error( $tmp_image ) ) {
				if ( is_file( $file_array['tmp_name'] ) ) {
					unlink( $file_array['tmp_name'] );
				}
				WP_CLI::warning(
					sprintf(
						// translators: 1: Post ID 2: Download URL for image 3: key for thumbnail.
						__( 'Post ID %1$d: Could not download %2$s for key %3$s.', 'wp-cli-csv-commands' ),
						$term_id,
						$image,
						$k
					)
				);
				foreach ( $tmp_image->errors as $error ) {
					WP_CLI::warning( $error[0] );
				}
				continue;
			}

			$image_type = exif_imagetype( $tmp_image );
			if ( ! in_array( $image_type, array_keys( self::IMAGE_TYPES ), true ) ) {
				WP_CLI::warning(
					sprintf(
						// translators: 1: Post ID 2: Download URL for image 3: key for thumbnail.
						__( 'Post ID %1$d: %2$s is not valid type for key %3$s. Only gif, jpeg and png supported.', 'wp-cli-csv-commands' ),
						$term_id,
						$image,
						$k
					)
				);
				continue;
			}

			$extension = self::IMAGE_TYPES[ $image_type ];
			if ( substr( $tmp_image, -strlen( $extension ) ) !== $extension ) {
				$new_name = $tmp_image . '.' . $extension;
				rename( $tmp_image, $new_name );
				$tmp_image = $new_name;
			}
			// Set variables for storage
			// fix file filename for query strings.
			$file_array['name']     = sanitize_file_name( $term_object->name . '.' . $extension );
			$file_array['tmp_name'] = $tmp_image;

			$attachment_id = media_handle_sideload( $file_array );
			if ( is_wp_error( $attachment_id ) ) {
				WP_CLI::warning(
					sprintf(
						// translators: 1: Post ID 2: Download URL for image 3: key for thumbnail.
						__( 'Row #%1$d: %2$s could not be attached for key %3$s.', 'wp-cli-csv-commands' ),
						$row_num,
						$image,
						$k
					)
				);
				foreach ( $attachment_id->errors as $error ) {
					WP_CLI::warning( $error[0] );
				}
				continue;
			}
			$this->save_row_meta( $term_id, array( $k => $attachment_id ), $row_num );

		}
		return $thumbs;
	}


	/**
	 * Determines wether or not a string is just an integer
	 *
	 * @param string $val - the string.
	 * @return boolean
	 */
	private function is_string_an_int( $val ) {
		return preg_match( '/^\d+$/', $val );
	}

	/**
	 * Sanitize
	 *
	 * @param  string|array $func function callback.
	 * @param  any          $val  to be sanitized item.
	 * @return any          either sanitized or as-is.
	 */
	private function sanitize_using_func( $func, $val ) {
		if ( null !== $func ) {
			foreach ( $func as $f ) {
				$val = $f( $val );
			}
		}
		return $val;
	}
}
