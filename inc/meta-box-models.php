<?php
/**
* Metaboxes.php creates a custom metabox for the regulations taxonomy. Needs more
* modularity.
* 
**/
// On 'add_meta_boxes', rip out the old metaboxes and replace them with regulation_meta_box() (below).
namespace CFPB\Utils\MetaBox;
use \CFPB\Utils\Taxonomy as TaxUtils;
use \CFPB\Utils\MetaBox\View;
use \CFPB\Utils\MetaBox\Callbacks;
use \WP_Error as WP_Error;
use \DateTime;

class Models {
    public $title;
    public $slug;
    public $post_type;
    public $context;
    public $fields;
    public $priority;
    public $Callbacks; // obj A class containing other validation methods
    public $View; // obj A class containing template patterns
    public $error;
    private $selects = array(
        'select',
        'multiselect',
        'taxonomyselect',
        'tax_as_meta',
        'post_select',
        'post_multiselect'
    );
    private $inputs  = array(
        'text_area',
        'number',
        'text',
        'boolean',
        'email',
        'url',
        'date',
        'radio',
        'link',
    );
    private $other   = array( 'nonce', 'hidden', 'separator', 'fieldset' );
    
    public function __construct() {
        $this->Callbacks = new Callbacks();
        $this->View      = new View();
        $this->priority  = 'default';
        if ( ! is_array($this->post_type) ) {
            $this->post_type = array($this->post_type);
        }
        $this->error = '\WP_Error';
    }

    /**
     * The following three methods are dependency injection methods. They can be used
     * to replace Utils\MetaBox\Callbacks and Utils\MetaBox\View with your own callback
     * or view class. This is useful for unit testing purposes and for when you decide
     * you don't like the methods we've given you and need to replace them for some 
     * reason. Maybe you need different templates for something but don't think they
     * should be contributed back, use these methods to replace our templates with your
     * own.
     * 
     * @param obj $Class The class you want to inject into this plugin
     */
    public function set_callbacks( $Class ) {
        $this->Callbacks = $Class;
    }

    public function set_view( $Class ) {
        $this->View = $Class;
    }

    public function error_handler( $Class ) {
        $this->error = $Class;
    }

    public function check_post_type( $post_type ) {
        if ( post_type_exists( $post_type ) ) {
            $post_type = sanitize_key( $post_type );
        } else {
            $post_type = false;
        }
        return $post_type;
    }

    /**
    *
    * generate: Create a meta box based on a few parameters.
    *
    * This function allows developers with this plugin installed to easily
    * instantiate meta boxes into different edit screens of their WordPress
    * install. Inspired by Django forms. The generate() method parses parameters
    * into an objeect, the last property of which is passed to the build()
    * callback. Generating metaboxes is as simple as hooking this method into
    * the `add_meta_boxes` action hook.
    *
    * @since 1.0
    *
    * @uses wp_parse_args To determine the desired differences from defaults
    * @uses add_meta_box WordPress API To generate the metabox
    * @uses \CFPB\Utils\MetaBox\Template\HTML(); For generating form fields
    * @uses add_meta_box (WP Core) To instantiate the meta box
    * @uses $this->check_post_type To check whether the post type given in
    *       $this->post_type exists
    * @uses \WP_Error Error handling is done with WordPress if invalid contexts or 
    *        post types are supplied
    **/
    public function generate( ) {
        $parts = array( 'normal', 'advanced', 'side', );
        if ( ! in_array( $this->context, $parts ) ) {
            $error = new $this->error( 'context', __( 'Invalid context: ' . $this->context ) );
            echo $error->get_error_message('context');
            return;
        }

        $fields = $this->fields;
        $post_types = $this->post_type;
        foreach ( $post_types as $p ) {
            $exists = $this->check_post_type($p);
            if ( $exists != false ) {
                add_meta_box(
                $id = $this->slug,
                $title = $this->title,
                $callback = array( $this->View, 'ready_and_print_html' ),
                $post_type = $p,
                $context = $this->context,
                $priority = $this->priority,
                $callback_args = $fields
            );
        } else {
            $error = new $this->error( 'post_type', 'Invalid post type: ' . $p);
            echo $error->get_error_message('post_type');
        }
    }
}

/**
 * validate_link validates a field with type = 'link'
 * 
 * @param  arr $field   The field to validate, normally passed by looping through 
 *                      $this->fields
 * @param  int $post_id The post ID to have post values saved to
 * @return void         No return value, updates or adds post meta if successful. New
 *                      metadata are saved to the post as an array of the form
 *                      array(0 => 'url', 1 => 'text')
 */
public function validate_link( $field, $post_id ) {
    $key = $field['meta_key'];
    if ( array_key_exists('max_num_forms', $field['params'] ) ) {
        $count = $field['params']['max_num_forms'] - 1;
    } else {
        $count = 1;
    }
    for ( $i = 0; $i <= $count; $i++ ) {
        if ( array_key_exists("{$key}_url_{$i}", $_POST) AND array_key_exists("{$key}_text_{$i}", $_POST) ) {
            $url = $_POST["{$key}_url_{$i}"];
            $text = $_POST["{$key}_text_{$i}"];
            $full_link = array( 0 => $url, 1 => $text );
            $meta_key = $key . "_{$i}";
            $existing = get_post_meta( $post_id, $meta_key, $single = false );
            if ( empty( $_POST[$key . '_url_' . $i] ) || empty( $_POST[$key.'_text_' . $i]) ) {
                delete_post_meta( $post_id, $meta_key);
            } elseif ( empty($existing) ) {
                add_post_meta( $post_id, $meta_key, $url, false );
                add_post_meta( $post_id, $meta_key, $text, false );
            } elseif ( $existing != $full_link ) {
                update_post_meta( $post_id, $meta_key, $url, $existing[0] );
                update_post_meta( $post_id, $meta_key, $text, $existing[1] );
            }

            $meta_key = $key;
        } else {
            continue;
        }
    }
}

public function validate_formset_of_fieldsets( $field, $post_id ) {
    $key = $field['meta_key'];
    if ( array_key_exists('max_num_forms', $field['params'] ) ) {
        $count = $field['params']['max_num_forms'];
    } else {
        $count = 1;
    }
    $validate = array();
    for ( $i = 0; $i < $count; $i++ ) {
        $ready[$i] = $field;
        $ready[$i]['meta_key'] .= '_' . $i;
        $ready[$i]['slug'] .= '_' . $i;
        // $ready[$i]['title'] .= ' ' . $i;
        for ($j = 0; $j < count( $field['fields'] ); $j++ ){
            $meta_key = $ready[$i]['fields'][$j]['meta_key'];
            $ready[$i]['fields'][$j]['meta_key'] = "{$key}_{$meta_key}_{$i}";
            $value = $this->validate( $post_id, $ready[$i]['fields'][$j] );
            if ( isset($value) ) {
                $validate[$ready[$i]['fields'][$j]['meta_key']] = $value;
            }
        }
    }
    return $validate;
}

// protected function debug_to_console( $data ) {

//     if ( is_array( $data ) )
//         $output = "<script>console.log( 'Debug Objects: " . implode( ',', $data) . "' );</script>";
//     else
//         $output = "<script>console.log( 'Debug Objects: " . $data . "' );</script>";

//     echo $output;
// }
/**
 * validate_select validates a <select> field
 * 
 * @param  arr $field   The field to validate, normally passed by looping through 
 *                      $this->fields
 * @param  int $post_id The post ID to have post values saved to
 * @return void         No return value, adds or deletes post meta if successful. New
 *                      data are saved to the post as new values in an array or 
 *                      deleted.
 */

public function validate_select( $field, $post_id ) {
    $key = $field['meta_key'];
    if ( !isset( $_POST[$key] ) ) {
        delete_post_meta( $post_id, $key);
        return;
    }
    if ( array_key_exists($key, $_POST) ) {
        $existing = get_post_meta( $post_id, $key, false );
        $data = $_POST[$key];
        foreach ( (array)$data as $d ) {
            // Adding or updating terms
            $term = sanitize_text_field( $d );
            $e_key = array_search($term, $existing);
            if ( ! in_array($d, (array)$existing) ) {
                // if the term is not in $existing, it's a new term, add it
                // we use add_post_meta instead of update so we can have more
                // than one value on the array
                add_post_meta( $post_id, $key, $term );
            }
        }
        // delete terms if they're not in the $_POST data
        foreach ( (array)$existing as $e ) {
            if ( ! in_array($e, (array)$data) ) {
                delete_post_meta( $post_id, $key, $meta_value = $e );
            }
        }
    }
}

public function validate_taxonomyselect($field, $post_id) {
    $key = $field['slug'];
    if ( isset($_POST[$key] )) {
        $term = sanitize_text_field( $_POST[$key] );
        $term_exists = get_term_by('id', $term, $field['taxonomy']);
        if ( $term_exists ){
            wp_set_object_terms(
            $post_id,
            $term_exists->name,
            $field['taxonomy'],
            $append = $field['multiple']
        );
    } else {
        wp_set_object_terms(
        $post_id,
        $term,
        $field['taxonomy'],
        $append = $field['multiple']
    );
    }
}
}

/** 
 * Validates a date field by converting $_POST keys into date strings before passing
 * to a date method in $this->Callbacks->date()
 * 
 * @param  array $field    The field to be processed
 * @param  [type] $post_id The ID of the object to be manipulated
 * @return void
 */
public function validate_date($field, $post_id) {
    $year = $field['taxonomy'] . '_year';
    $month = $field['taxonomy'] . '_month';
    $day = $field['taxonomy'] . '_day';
    $data = array($field['taxonomy'] => '');
    if ( isset($_POST[$month]) ) {
        $data[$field['taxonomy']] = $_POST[$month];
    }
    if ( isset( $_POST[$day] ) ) {
        $data[$field['taxonomy']] .= ' ' . $_POST[$day];
    }
    if ( isset( $_POST[$year] ) ) {
        $data[$field['taxonomy']] .= ' ' . $_POST[$year];
    }
    $date = DateTime::createFromFormat('F j Y', $data[$field['taxonomy']]);
    if ( $date ) {
        $this->Callbacks->date( $post_id, $field['taxonomy'], $multiples = $field['multiples'], $data );
    }
}

/**
 * Checks that data is coming through in the types we expect or ferries out form 
 * data to the appropriate validator. Run before save to ensure you're saving
 * correct data. Essentially this takes in $_POST and pushes all the good stuff from
 * $_POST into a separate, cleaned array and returns the cleaned data.
 *
 * @param int $post_ID The post targeted for custom data
 * 
 * @return array A version of $_POST with cleaned data ready to be sent to a save method
 *               like $this->save()
 */
public function validate( $post_ID, $field ) {
    // $data = array_intersect_key($_POST, $this->fields);
    if ( array_key_exists( 'meta_key', $field ) ) {
        $key = $field['meta_key'];
    } elseif ( array_key_exists( $field['taxonomy'], $field ) ) {
        $key = $field['taxonomy'];
    } else {
        return null;
    }
    if ( array_key_exists('do_not_validate', $field) ) {
        return;
    }
    if ( ! array_key_exists( 'type', $field ) ) {
        return;
    }


    /* if this field is a taxonomy select, date, link or select field, we
       send it out to another validator
    */
    if ( $field['type'] == 'taxonomyselect') {
        $this->validate_taxonomyselect( $field, $post_ID );
        return;
    } elseif ( in_array( $field['type'], $this->selects ) ) {
        $this->validate_select( $field, $post_ID );
        return;
    } elseif ( $field['type'] === 'date' ) {
        $this->validate_date( $field, $post_ID );
        return;
    } elseif ( $field['type'] === 'link' ) {
        $this->validate_link($field, $post_ID);
        return;
    } else {
        /* 
            For most field types we just need to make sure we have the data
            we expect from the form and sanitize them before sending them to
            save
        */
            if ( ! array_key_exists( $key, $_POST ) ) {
                return;
            }
            $value = $_POST[$key];
            if ( $field['type'] === 'number' ) {
                if ( is_numeric( $value ) ) {
                    // if we're expecting a number, make sure we get a number
                    $postvalues = intval( $value ); 
                } else {
                    $postvalues = null;
                }
            } elseif ( $field['type'] === 'url' && isset( $value ) ) {
                // if we're expecting a url, make sure we get a url
                $postvalues = esc_url_raw( $value ); 
            } elseif ( $field['type'] === 'email' ) {
                // if we're expecting an email, make sure we get an email
                $postvalues = sanitize_email( $value ); 
            } elseif ( ! empty( $value ) && ! is_array($value)) {
                // make sure whatever we get for anything else is a string
                $postvalues = (string)$value;
            } else {
                $postvalues = null;
            }
        }
    return $postvalues;
}

/**
 * Takes cleaned $_POST data and save it as custom post meta
 * 
 * @param  int $post_ID      The post we save data to
 * @param  array $postvalues Cleaned version of $_POST or some other array of trusted 
 *                           data to be saved
 * @return nothing           Either deletes or updates post meta or returns empty
 */
public function save( $post_ID, $postvalues ) {
    // Do nothing if we're auto saving
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
    return;

    if ( empty( $postvalues ) ) {
        // if we're passed an empty array, don't do anything
        return;
    }

    // save post data for any fields that sent them
    foreach ( $postvalues as $key => $value ) {
        $existing = get_post_meta( $post_ID, $key, $single = true );
        if ( $value == null && isset($existing) ) {
            delete_post_meta($post_ID, $key);
        } elseif ( isset($value) ) {
            update_post_meta( $post_ID, $meta_key = $key, $meta_value = $value );
        } else {
            return;
        }
    }
}

/**
 * Runs validate, then save on $_POST data
 * 
 * @param  int $post_ID The id of the object we're saving
 * @return void
 */
public function validate_and_save( $post_ID ) {
    $validate = array();
    foreach ( $this->fields as $field ) {
        if ( $field['type'] == 'fieldset') {
            if ( isset( $field['params']['is_formset_of_fieldsets'] ) ) {
                $prevalidate = $this->validate_formset_of_fieldsets( $field, $post_ID );
                foreach ( $prevalidate as $f ) {
                    $validate[$f['meta_key']] = $f;
                }
            } else {
                foreach ( $field['fields'] as $f ) {
                    $f['meta_key'] = $field['meta_key'] . '_' . $f['meta_key'];
                    $value = $this->validate( $post_ID, $f );
                    $validate[$f['meta_key']] = $value;
                }
            }
        } else {
            $key = $field['meta_key'];
            $value = $this->validate( $post_ID, $field );
            if ( isset($value) ) {
                $validate[$key] = $value;
            }
        }
    }
    $this->save( $post_ID, $validate );
}
/**
* Moved premanently: This function is now located in the \CFPB\Utils\MetaBox\Template namespace, in the HTML class.
*
* @since v1.0
*
**/
public function date_meta_box( $taxonomy, $tax_nice_name, $mutliples = false ) {
    $error = new $this->error( 'moved', __( 'This function has moved to the \CFPB\Utils\MetaBox\HTML namespace. Look for it there as simply date()!' ) );
    echo $error->get_error_message('moved');
}
}
