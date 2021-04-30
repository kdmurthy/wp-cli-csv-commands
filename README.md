# WP CLI CSV

This plugin adds a `csv` command to Wordpress CLI.

### Creadits

Initial ideas and some code from: https://github.com/jeffsebring/wp-cli-import-csv

### Installation

You can install this as a WP CLI package using `wp package` command or as a WordPress plugin.

### `import` sub command

    Imports data from a CSV file into WordPress
    
    ## OPTIONS
    
    <file>
    : The CSV file from which to import data. Required.
    
    --mapping=<mapping_file>
    : A JSON file describing the header mappings.
    
    --post-type=<post_type>
    : The post type to be used for inserting data. Required.
    
    [--dry-run]
    : Turn on dry run mode. No updates to WordPress takes place.
    
    [--author=<author>]
    : The user ID or username.
    
    [--status=<status>]
    : The post status. Defaults to `publish`.
    
    [--verbose]
    : Turn on verbose mode.
    
    [--thumbnail_base_url=<thumbnail_base_url>]
    : URL part to thumbnails.
    
    [--header]
	: Does the CSV has a header row? Defaults to true. If set to false, header names are autogenerated
	: in the form of 'C1', 'C2' etc.
    
    [--strict]
    : Reject lines where the number of fields do not match the header. Defaults to false.
    
    [--delimiter=<delimiter>]
    : The CSV file delimiter. Defaults to ','.
    
    [--enclosure=<enclosure>]
    : The CSV file enclosure for fields. Defaults to '"'.
    
    [--escape=<escape>]
    : The CSV file escape character. Defaults to '\\'.
    
    ## EXAMPLES
    
        # Generate a 'movie' post type for the 'simple-life' theme
        $ wp csv import quotes_dataset-partial.csv --strict --no-header --post-type=post --status=publish --mapping=quotes_dataset-mapping.json
        Success: Wrote 100 records.    

### Mapping file format

The command requires a mapping file in JSON format to map each of the CSV header fields to the corresponding WordPress post field.

The file consists of a JSON object. Each key of the object represents an header element from the CSV file. The value is an object consisting of the following fields:

* `type`: The type of the field container. One of `post`, `taxonomy`, `meta` or `thumbnail`.
* `name`: The name of the field. When `type` is `post` should be one of WordPress post fields. For `taxonomy` and `meta` the name can appear in multiple entries. For `taxonomy` all terms will be written. For `meta` a single entry is written as an array.
* `sanitize`: A list of WordPress functions used to sanitize the field.
* `delimiter`: If the CSV field contains multiple values, you can use `delimiter` to `explode` the value into an array for `taxonomy` and `meta` type fields.

#### Example

The following example imports a CSV file containing quotations into WordPress `post` post type. The full CSV file and the mapping file is in the `examples` folder.

The CSV File (`quotes_dataset.csv`):

	"Mans vanity transgresses death","Erik Christian Haugaard, The Samurai's Tale","death, pride, vanity"
	"Monsters are not born they are made.","Valjeanne Jeffers","life-and-living, truth-of-life"
	"Coffee is to a Writer as Blood is to a Vampire!","Kade Cook","reality"

The Mapping file (`quotes_dataset-mapping.json`):

	{
	    "C1": {
		"type": "post",
		"sanitize": [ "wp_kses_post" ],
		"name": "post_title"
	    },
	    "C2": {
		"type": "taxonomy",
		"sanitize": [ "ucwords" ],
		"name": "category"
	    },
	    "C3": {
		"type": "taxonomy",
		"sanitize": [ "ucwords" ],
		"name": "post_tag",
		"delimiter": ","
	    }
	}

We can import using the following command:

```
  wp csv import quotes_dataset.csv --no-header --post-type=post --status=publish --mapping=quotes_dataset-mapping.json
```
Both these files should be available in the `WordPress` folder.
