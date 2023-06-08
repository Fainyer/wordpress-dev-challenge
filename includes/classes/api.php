<?php
if (!defined('ABSPATH')) {
    die('Direct access not permitted.');
}

/**
 * Custom REST API for ReactJS integration.
 */
class ReactApi
{
    /**
     * Initialize the API.
     */
    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
        // Agregar el filtro de autenticación a las solicitudes de la API REST
        add_filter('rest_pre_dispatch', array($this, 'authenticate_api_requests'));
    }

    /**
     * Register the custom routes.
     */
    public function register_routes()
    {
        register_rest_route('react/v1', '/posts', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_posts'),
        ));

        register_rest_route('react/v1', '/posts/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post'),
        ));

        register_rest_route('react/v1', '/posts/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_post'),
            'permission_callback' => array($this, 'check_authentication'),
        ));

        register_rest_route('react/v1', '/posts/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_post'),
            'permission_callback' => array($this, 'check_authentication'),
        ));

        register_rest_route('react/v1', '/posts', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
            'permission_callback' => array($this, 'check_authentication'),
        ));
    }

    /**
     * Get all posts.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response data.
     */
    public function get_posts($request)
    {
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        );

        $posts = get_posts($args);

        $data = array();
        foreach ($posts as $post) {
            $data[] = $this->prepare_post_data($post);
        }

        return new WP_REST_Response($data, 200);
    }

    /**
     * Get a specific post.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response data.
     */
    public function get_post($request)
    {
        $post_id = $request->get_param('id');
        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return new WP_REST_Response(array('message' => 'Post not found'), 404);
        }

        $data = $this->prepare_post_data($post);

        return new WP_REST_Response($data, 200);
    }

    /**
     * Create a new post.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response data.
     */
    public function create_post($request)
    {
        // Perform input validation and sanitization
        $title = sanitize_text_field($request->get_param('title'));
        $content = wp_kses_post($request->get_param('content'));
        $meta_fields = $request->get_param('meta_fields');

        // Create the post
        $post_id = wp_insert_post(array(
            'post_title' => $title,
            'post_content' => $content,
            'post_type' => 'post',
            'post_status' => 'publish',
        ));

        // Set meta fields
        if ($post_id && is_array($meta_fields)) {
            foreach ($meta_fields as $meta_field) {
                $key = sanitize_text_field($meta_field['key']);
                $value = sanitize_text_field($meta_field['value']);
                update_post_meta($post_id, $key, $value);
            }
        }

        if ($post_id) {
            $post = get_post($post_id);
            $data = $this->prepare_post_data($post);

            return new WP_REST_Response($data, 201);
        } else {
            return new WP_REST_Response(array('message' => 'Failed to create post'), 500);
        }
    }

    /**
     * Update a post.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response data.
     */
    public function update_post($request)
    {
        $post_id = $request->get_param('id');
        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return new WP_REST_Response(array('message' => 'Post not found'), 404);
        }

        // Perform input validation and sanitization
        $title = sanitize_text_field($request->get_param('title'));
        $content = wp_kses_post($request->get_param('content'));
        $meta_fields = $request->get_param('meta_fields');

        // Update the post
        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => $title,
            'post_content' => $content,
        ));

        // Set meta fields
        if (is_array($meta_fields)) {
            foreach ($meta_fields as $meta_field) {
                $key = sanitize_text_field($meta_field['key']);
                $value = sanitize_text_field($meta_field['value']);
                update_post_meta($post_id, $key, $value);
            }
        }

        $post = get_post($post_id);
        $data = $this->prepare_post_data($post);

        return new WP_REST_Response($data, 200);
    }

    /**
     * Delete a post.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response data.
     */
    public function delete_post($request)
    {
        $post_id = $request->get_param('id');
        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return new WP_REST_Response(array('message' => 'Post not found'), 404);
        }

        // Delete the post
        wp_delete_post($post_id, true);

        return new WP_REST_Response(null, 204);
    }

    /**
     * Prepare post data for the response.
     *
     * @param WP_Post $post The post object.
     * @return array The prepared post data.
     */
    private function prepare_post_data($post)
    {
        $data = array(
            'id' => $post->ID,
            'slug' => $post->post_name,
            'link' => get_permalink($post),
            'title' => $post->post_title,
            'featured_image' => get_the_post_thumbnail_url($post),
            'categories' => $this->get_post_categories($post),
            'content' => $post->post_content,
            'meta_fields' => $this->get_post_meta_fields($post),
        );

        return $data;
    }

    /**
     * Get the categories for a post.
     *
     * @param WP_Post $post The post object.
     * @return array The post categories.
     */
    private function get_post_categories($post)
    {
        $categories = get_the_category($post);
        $data = array();

        foreach ($categories as $category) {
            $data[] = array(
                'id' => $category->term_id,
                'title' => $category->name,
                'description' => $category->description,
            );
        }

        return $data;
    }

    /**
     * Get the meta fields for a post.
     *
     * @param WP_Post $post The post object.
     * @return array The post meta fields.
     */
    private function get_post_meta_fields($post)
    {
        $meta_fields = get_post_meta($post->ID);
        $data = array();

        foreach ($meta_fields as $key => $value) {
            $data[] = array(
                'key' => $key,
                'value' => $value[0],
            );
        }

        return $data;
    }

    /**
     * Check the authentication for API requests.
     *
     * @param WP_REST_Request $request The request object.
     * @return bool Whether the request is authenticated.
     */
    public function check_authentication($request)
    {
        // Implement your authentication logic here
        // You can retrieve the authentication key from the plugin settings
        return true; // Return true for demonstration purposes
    }


    /**
     * Verificar la autenticación para las solicitudes de la API REST.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Request|WP_Error The modified request object or error.
     */
    public function authenticate_api_requests($request)
    {
        $api_key = get_option('api_key');

        // Verificar si se proporciona la clave de autenticación en los encabezados de la solicitud
        $auth_key = $request->get_header('X-API-Key');
        if ($auth_key && $auth_key === $api_key) {
            return $request; // La autenticación es exitosa, continuar con la solicitud
        }

        // Si no se proporciona la clave de autenticación o no coincide, devolver un error de autenticación
        return new WP_Error('rest_unauthorized', 'Unauthorized', array('status' => 401));
    }
}

// Create an instance of the API class and initialize it
$react_api = new ReactApi();
$react_api->init();
