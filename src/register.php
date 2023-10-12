<?php

declare(strict_types=1);

if (!function_exists('register_virtual_fields')) {
  function register_virtual_fields(array|string $postTypes, array $virtualFields)
  {
    if (!is_array($postTypes)) $postTypes = [$postTypes];

    // add virtual fields to post objects returned by `get_posts` and/or `WP_Query`:
    add_filter("the_posts", function ($posts, $query) use ($postTypes, $virtualFields) {
      if (!in_array($query->query_vars['post_type'], $postTypes)) return $posts;
      if (!is_array($posts) || !count($posts)) return $posts;

      return array_map(function ($post) use ($virtualFields) {
        // add each virtual field to post object:
        foreach ($virtualFields as $_field) {
          $field = $_field->getSettings();
          if (in_array('core', $field['excludedFrom'])) continue;

          $post->{$field['name']} = $_field->getValue($post);
        }
        return $post;
      }, $posts);
    }, 20, 2);

    // add virtual fields to post REST API responses:
    add_action('rest_api_init', function () use ($postTypes, $virtualFields) {
      foreach ($virtualFields as $_field) {
        $field = $_field->getSettings();
        if (in_array('rest', $field['excludedFrom'])) continue;

        register_rest_field(
          $postTypes,
          $field['name'],
          array(
            'get_callback'    => function ($post) use ($_field) {
              return $_field->getValue($post);
            },
            'update_callback' => null,
            'schema'          => null,
          )
        );
      }
    }, 1);

    // add virtual fields to post "revisions" REST API responses (requires different approach than above):
    add_filter('rest_prepare_revision', function ($response, $post) use ($postTypes, $virtualFields) {
      $parentPost = get_post($post->post_parent); // get the parent's post object
      if (!in_array($parentPost->post_type, $postTypes)) return $response;

      $data = $response->get_data();

      $parentPost->post_content = $post->post_content; // swap parent's content for revision's content, before we pass $parentPost into getValue()

      foreach ($virtualFields as $_field) {
        $field = $_field->getSettings();
        if (in_array('rest_revisions', $field['excludedFrom'])) continue;

        $data[$field['name']] = $_field->getValue($parentPost);
      }

      return rest_ensure_response($data);
    }, 10, 2);
  }
}