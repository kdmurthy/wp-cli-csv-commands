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
class ImportPosts extends WP_CLI_Command {

	/**
	 * POST fields
	 *
	 * @var array
	 */

	const POST_FIELDS = array(
		'post_author',
		'post_category',
		'post_content',
		'post_date',
		'post_date_gmt',
		'post_excerpt',
		'post_name',
		'post_password',
		'post_status',
		'post_title',
		'menu_order',
		'comment_status',
		'ping_status',
		'pinged',
		'tags_input',
		'to_ping',
		'tax_input',
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
		if ( ! isset( $data['post'] ) ) {
			WP_CLI::warning(
				sprintf(
					// translators: 1: Row number.
					'Row #%d: No post data.',
					$row_num
				)
			);
			return false;
		}
		$post_id = $this->insert_post_data( $data, $row_num );
		if ( ! $post_id ) {
			return false;
		}
		$saved_data = array();

		$saved_data['post_id'] = $post_id;
		$saved_data['post']    = $data['post'];

		if ( isset( $post_id ) && isset( $data['meta'] ) ) {
			$saved_data['meta'] = $this->save_row_meta( $post_id, $data['meta'], $row_num );
		}

		if ( isset( $data['taxonomy'] ) ) {
			$saved_data['terms'] = $this->save_row_taxonomies( $post_id, $data['taxonomy'] );
		}

		if ( isset( $data['thumbnail'] ) ) {
			if ( isset( $data['post']['post_author'] ) ) {
				$author = $data['post']['post_author'];
			} else {
				$author = $this->csv->author;
			}

			// Suppress Imagick::queryFormats strict static method error from WP core.
			// phpcs:ignore
			$saved_data['thumbnails'] = @$this->save_row_thumbnails( $post_id, $data['thumbnail'], $data['post'], $author );
		}
		/**
		 * Perform an action post-update.
		 *
		 * This action runs after all data is updated. The saved data and the post type are passed as arguments.
		 *
		 * @param array $saved_data - Data containing post, taxonomies, meta and thumbnails
		 * @param string $post_type - The post type being used
		 */
		do_action( 'csv_commands_write_row', $saved_data, $this->csv->post_type );
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

			if ( ! in_array( $type, array( 'post', 'meta', 'taxonomy', 'thumbnail' ), true ) ) {
				$messages[] = sprintf(
					// translators: 1: CSV header value 2: Type provided.
					__( '%1$s: %2$s is an unsupported field type. Possible types are meta, post, taxonomy, thumbnail!', 'wp-cli-csv-commands' ),
					$head,
					$type
				);
			}

			if ( 'post' === $type && null !== $delimiter ) {
				$messages[] = sprintf(
					// translators: 1: CSV header value.
					__( '%1$s: Post type does not support delimiter.', 'wp-cli-csv-commands' ),
					$head
				);
			}

			if ( 'post' === $type && ! $this->is_valid_post_field( $name ) ) {
				$messages[] = sprintf(
					// translators: 1: CSV header value 2: Type provided.
					__( '%1$s: Invalid post field %2$s used.', 'wp-cli-csv-commands' ),
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

			if ( 'taxonomy' === $type && ! taxonomy_exists( $name ) ) {
				$messages[] = sprintf(
					// translators: 1: CSV header value 2: Taxonomy.
					__( '%1$s: %2$s  is an not a registered taxonomy.', 'wp-cli-csv-commands' ),
					$head,
					$name
				);
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
	private function is_valid_post_field( $field_name ) {
		return in_array( $field_name, self::POST_FIELDS, true );
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
		return 'post' !== $type;
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
	 * Insert row post data
	 *
	 * @access private
	 * @param array   $data data to be saved.
	 * @param integer $row_num currently processed row.
	 * @return bool true on success
	 */
	private function insert_post_data( $data, $row_num ) {
		$post_data = $data['post'];
		if ( ! isset( $post_data['post_title'] ) || '' === $post_data['post_title'] ) {
			WP_CLI::warning(
				sprintf(
					// translators: 1: Row number.
					__( 'row #%d skipped. Needs a post_title.', 'wp-cli-csv-commands' ),
					$row_num
				)
			);
			return false;
		}

		$post['post_title'] = $post_data['post_title'];

		if ( isset( $post_data['post_type'] ) && post_type_exists( $post_data['post_type'] ) ) {
			WP_CLI::warning(
				sprintf(
					// translators: 1: Post type of the post 2: post type given on command line.
					__( 'Post type %1$s validated and overriding %2$s.', 'wp-cli-csv-commands' ),
					$post_data['post_type'],
					$this->csv->post_type
				)
			);
			$post['post_type'] = $post_data['post_type'];
		} else {
			$post['post_type'] = $this->csv->post_type;
		}

		foreach ( $post_data as $k => $v ) {
			if ( in_array( $k, self::POST_FIELDS, true ) ) {
				$post[ $k ] = $v;
			}
		}

		if ( isset( $post['post_author'] ) && is_int( $post['post_author'] ) ) { //phpcs:ignore
		} elseif ( isset( $post['post_author'] ) && is_string( $post['post_author'] ) && username_exists( $post['post_author'] ) ) {
			$post['post_author'] = username_exists( $post['post_author'] );
		} elseif ( isset( $this->csv->author ) && $this->is_string_an_int( $this->csv->author ) ) {
			$post['post_author'] = $this->csv->author;
		}
		$post['post_status'] = $this->csv->status;
		$old_post            = get_page_by_title( $post['post_title'], ARRAY_A, $post['post_type'] );
		if ( null === $old_post ) {
			$old_post = array();
		}
		$post = wp_parse_args( $post, $old_post );

		/**
		 * Filters the contents of the post being inserted/updated.
		 *
		 * This filter runs after the JSON data is read but before validation. Returning false from the filter
		 * aborts the process.
		 *
		 * @param array $post - The post data
		 * @param array $data - The data processed from CSV
		 * @param string $post_type - the post type
		 *
		 * @return array should return post data
		*/
		$post = apply_filters( 'csv_commands_pre_insert_post', $post, $data, $this->csv->post_type );

		$post_id = wp_insert_post( $post );
		if ( ! is_wp_error( $post_id ) ) {
			return $post_id;
		}
		WP_CLI::warning(
			sprintf(
				// translators: 1: Row number.
				__( 'Row #%d: Error inserting post (Errors below)', 'wp-cli-csv-commands' ),
				$row_num
			)
		);
		foreach ( $post_id->errors as $error ) {
			WP_CLI::warning( $error[0] );
		}
		return false;
	}

	/**
	 * Save row metadata
	 *
	 * @access private
	 * @param integer $post_id post id to set meta.
	 * @param array   $meta_data meta data to be saved.
	 * @param integer $row_num row number to display errors.
	 *
	 * @return array Metadata as inserted.
	 */
	private function save_row_meta( $post_id, $meta_data, $row_num ) {

		$meta = array();
		foreach ( $meta_data as $k => $value ) {
			$prev_value = get_post_meta( $post_id, $k, true );
			if ( $prev_value === $value ) {
				$meta[ $k ] = $value;
				continue;
			}
			$r = update_post_meta( $post_id, $k, $value );
			if ( $r ) {
				$meta[ $k ] = $value;
			} else {
				WP_CLI::warning(
					sprintf(
						// translators: 1: Post ID 2: Meta key name 3: Value.
						__( 'Post ID %1$d: Meta key %2$s could not be added with value %3$s.', 'wp-cli-csv-commands' ),
						$post_id,
						$k,
						$value
					)
				);
			}
		}
		return $meta;
	}

	/**
	 * Save row taxomies
	 *
	 * @access private
	 * @param integer $post_id post id to attach terms to.
	 * @param array   $taxonomies taxonomy data to be saved.
	 * @return array Taxonomy terms as inserted
	 */
	private function save_row_taxonomies( $post_id, $taxonomies ) {
		$terms = array();
		foreach ( $taxonomies as $k => $value ) {
			wp_set_object_terms( $post_id, $value, $k );
			$terms[ $k ] = $value;
		}
		return $terms;
	}

	/**
	 * Save row thumbnails
	 *
	 * @access private
	 * @param integer        $post_id post id to attach thumbnails to.
	 * @param array          $thumbnails thumbnails to be imported.
	 * @param array          $post parent post data.
	 * @param string|integer $author author id or name.
	 * @return array Thumbnail IDs as inserted
	 */
	private function save_row_thumbnails( $post_id, $thumbnails, $post = array(), $author = null ) {
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
						$post_id,
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
						$post_id,
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
			$file_array['name']     = sanitize_file_name( $post['post_title'] . '.' . $extension );
			$file_array['tmp_name'] = $tmp_image;

			// Image Metadata.
			if ( isset( $post['post_title'] ) ) {
				$image_meta['post_title'] = wp_kses_post( $post['post_title'] );
			}

			$image_meta['post_parent'] = $post_id;
			if ( isset( $author ) && is_int( $author ) ) {
				$image_meta['post_author'] = $author;
			} elseif ( isset( $author ) && is_string( $author ) && username_exists( $author ) ) {
				$image_meta['post_author'] = username_exists( $author );
			}

			$attachment_id = media_handle_sideload( $file_array, $post_id, wp_kses_post( $post['post_excerpt'] ), $image_meta );
			if ( is_wp_error( $attachment_id ) ) {
				WP_CLI::warning(
					sprintf(
						// translators: 1: Post ID 2: Download URL for image 3: key for thumbnail.
						__( 'Post ID %1$d: %2$s could not be attached for key %3$s.', 'wp-cli-csv-commands' ),
						$post_id,
						$image,
						$k
					)
				);
				foreach ( $attachment_id->errors as $error ) {
					WP_CLI::warning( $error[0] );
				}
				continue;
			}

			$thumbs[ $k ] = array(
				'attachment_id' => $attachment_id,
				'file'          => $value,
			);

			if ( 'featured_image' === $k ) {
				if ( set_post_thumbnail( $post_id, $attachment_id ) ) {
					return $thumbs;
				}
			}
			if ( ! add_post_meta( $post_id, $this->csv->post_type . '_' . $k . '_thumbnail_id', $attachment_id ) ) {
				WP_CLI::warning(
					sprintf(
						// translators: 1: Post ID 2: Download URL for image 3: key for thumbnail.
						__( 'Post ID %1$d: %2$s could not be attached for key %3$s.', 'wp-cli-csv-commands' ),
						$post_id,
						$image,
						$k
					)
				);
			}
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
