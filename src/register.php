<?php

declare(strict_types=1);

use CloakWP\Core\Utils;


if (!function_exists('register_virtual_fields')) {
  function register_virtual_fields(array|string $postTypes, array $virtualFields)
  {
    $MAX_RECURSIVE_DEPTH = 2; // prevents infinite loops (limiting recursion to the specified number) caused by the `value` method of a VirtualField triggering one of the filters used below

    if (!is_array($postTypes))
      $postTypes = [$postTypes];

    // add virtual fields to post objects returned by `get_posts` and/or `WP_Query`:
    add_filter("the_posts", function ($posts, $query) use ($postTypes, $virtualFields, $MAX_RECURSIVE_DEPTH) {
      if (!is_array($posts) || !count($posts))
        return $posts;

      // Pre-filter virtual fields by exclusion to avoid checking in the inner loop
      $coreVirtualFields = array_filter($virtualFields, function ($_field) {
        $field = $_field->getSettings();
        return !in_array('core', $field['excludedFrom']);
      });

      if (empty($coreVirtualFields)) {
        return $posts;
      }

      return array_map(function (\WP_Post $post) use ($postTypes, $coreVirtualFields, $MAX_RECURSIVE_DEPTH) {
        if (in_array($post->post_type, $postTypes)) {
          // add each virtual field to post object:
          foreach ($coreVirtualFields as $_field) {
            if ($_field->_getRecursiveIterationCount() < $MAX_RECURSIVE_DEPTH) {
              $post->{$_field->getSettings()['name']} = $_field->getValue($post);
              $_field->_resetRecursiveIterationCount();
            }
          }
        }

        return $post;
      }, $posts);
    }, 20, 2);

    // add virtual fields to post REST API responses:
    add_action('rest_api_init', function () use ($postTypes, $virtualFields) {
      // Pre-filter virtual fields for REST API
      $restVirtualFields = array_filter($virtualFields, function ($_field) {
        $field = $_field->getSettings();
        return !in_array('rest', $field['excludedFrom']);
      });

      if (empty($restVirtualFields)) {
        return;
      }

      foreach ($restVirtualFields as $_field) {
        $field = $_field->getSettings();
        register_rest_field(
          $postTypes,
          $field['name'],
          array(
            'get_callback' => function ($post) use ($_field) {
              $postObj = Utils::asPostObject($post);
              return $_field->getValue($postObj);
            },
            'update_callback' => null,
            'schema' => [
              'context' => ['view'], // we assume that virtual fields aren't needed in "edit" context (i.e. when updating a post)
            ],
          )
        );
      }
    }, 1);

    // add virtual fields to post "revisions" REST API responses (requires different approach than above):
    add_filter('rest_prepare_revision', function ($response, $post) use ($postTypes, $virtualFields) {
      $parentPost = get_post($post->post_parent); // get the parent's post object
      if (!in_array($parentPost->post_type, $postTypes))
        return $response;

      // Pre-filter virtual fields for REST revisions
      $revisionVirtualFields = array_filter($virtualFields, function ($_field) {
        $field = $_field->getSettings();
        return !in_array('rest_revisions', $field['excludedFrom']);
      });

      if (empty($revisionVirtualFields)) {
        return $response;
      }

      $data = $response->get_data();
      $parentPost->post_content = $post->post_content; // swap parent's content for revision's content

      foreach ($revisionVirtualFields as $_field) {
        $data[$_field->getSettings()['name']] = $_field->getValue($parentPost);
      }

      return rest_ensure_response($data);
    }, 10, 2);

    // add virtual fields to ACF relational fields data
    // $addVirtualFieldsToAcfRelationalFields = function ($value, $post_id, $field) use ($postTypes, $virtualFields, &$addVirtualFieldsToAcfRelationalFields) {
    //   if (!$value)
    //     return $value;

    //   if (!is_array($value))
    //     $value = [$value];

    //   // Remove the filter to prevent infinite loop:
    //   // remove_filter('acf/format_value/type=relationship', $addVirtualFieldsToAcfRelationalFields, 10, 3);

    //   // Add virtual fields to posts:
    //   $modifiedPosts = array_map(function (WP_Post $relatedPost) use ($postTypes, $virtualFields) {
    //     if (!in_array($relatedPost->post_type, $postTypes))
    //       return $relatedPost;

    //     // add each virtual field to related post object:
    //     foreach ($virtualFields as $_field) {
    //       $field = $_field->getSettings();
    //       if (in_array('acf', $field['excludedFrom']))
    //         continue;

    //       $relatedPost->{$field['name']} = $_field->getValue($relatedPost);
    //     }

    //     return $relatedPost;
    //   }, $value);

    //   // Re-add the filter after the VirtualField `getValue` functions have executed:
    //   // add_filter('acf/format_value/type=relationship', $addVirtualFieldsToAcfRelationalFields, 10, 3);

    //   return $modifiedPosts;
    // };

    // add_filter('acf/format_value/type=relationship', $addVirtualFieldsToAcfRelationalFields, 10, 3);

    // add virtual fields to post responses via CloakWP Eloquent package
    add_filter('cloakwp/eloquent/posts', function ($posts) use ($postTypes, $virtualFields) {
      if (!is_array($posts) || !count($posts))
        return $posts;

      // Pre-filter virtual fields for Eloquent
      $eloquentVirtualFields = array_filter($virtualFields, function ($_field) {
        $field = $_field->getSettings();
        return !in_array('core', $field['excludedFrom']);
      });

      if (empty($eloquentVirtualFields)) {
        return $posts;
      }

      if (isset($posts['ID']) && $posts['ID']) {
        $posts = [$posts];
      }

      return array_map(function ($post) use ($postTypes, $eloquentVirtualFields) {
        if (!is_array($post))
          return $post;

        if (isset($post['post_type']) && in_array($post['post_type'], $postTypes)) {
          // add each virtual field to post object:
          /** @var \CloakWP\VirtualFields\VirtualField $_field */
          foreach ($eloquentVirtualFields as $_field) {
            $post[$_field->getSettings()['name']] = $_field->getValue(Utils::asPostObject($post));
          }
        }

        return $post;
      }, $posts);
    }, 10, 1);
  }
}