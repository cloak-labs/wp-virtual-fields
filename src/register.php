<?php

declare(strict_types=1);

use CloakWP\Core\Utils;


if (!function_exists('register_virtual_fields')) {
  function register_virtual_fields(array|string $postTypes, array $virtualFields)
  {
    if (!is_array($postTypes))
      $postTypes = [$postTypes];

    // add virtual fields to post objects returned by `get_posts` and/or `WP_Query`:
    add_filter("the_posts", function ($posts, $query) use ($postTypes, $virtualFields) {
      if (!is_array($posts) || !count($posts))
        return $posts;

      $coreVirtualFields = array_filter($virtualFields, function ($_field) {
        $field = $_field->getSettings();
        return !in_array('core', $field['excludedFrom']);
      });

      if (empty($coreVirtualFields)) {
        return $posts;
      }

      $isSecondaryRestQuery = (defined('REST_REQUEST') && REST_REQUEST) &&
        ($query instanceof \WP_Query) &&
        !$query->is_main_query();

      return array_map(function (\WP_Post $post) use ($postTypes, $coreVirtualFields, $isSecondaryRestQuery) {
        if (!in_array($post->post_type, $postTypes, true)) {
          return $post;
        }

        /**
         * Avoid mutating WP's cached WP_Post instances. This prevents "leaked" virtual field
         * values from appearing in later queries within the same request.
         */
        $post = clone $post;

        foreach ($coreVirtualFields as $_field) {
          $settings = $_field->getSettings();
          $fieldName = $settings['name'];
          $maxDepth = $_field->getMaxRecursiveDepth();

          /**
           * Generic "top-level only" behavior in REST:
           * If a field is configured with `maxRecursiveDepth(1)`, it can only appear at depth 0
           * (i.e. the post being directly serialized by the REST controller). Relationship/query
           * posts are effectively depth 1+, so we skip adding those fields in secondary REST queries.
           */
          if ($isSecondaryRestQuery && $maxDepth <= 1) {
            if (property_exists($post, $fieldName)) {
              unset($post->{$fieldName});
            }
            continue;
          }

          if ($_field->_getRecursiveIterationCount() < $maxDepth) {
            $post->{$fieldName} = $_field->getValue($post);
          } else {
            // If the field already exists from an earlier context, remove it at max depth.
            if (property_exists($post, $fieldName)) {
              unset($post->{$fieldName});
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
        $fieldName = $field['name'];
        $maxDepth = $_field->getMaxRecursiveDepth();
        register_rest_field(
          $postTypes,
          $fieldName,
          array(
            'get_callback' => function ($post) use ($_field, $fieldName, $maxDepth) {
              $postObj = Utils::asPostObject($post);
              if (!$postObj) {
                return null;
              }

              /**
               * Enforce max recursion depth for REST as well.
               */
              if ($_field->_getRecursiveIterationCount() >= $maxDepth) {
                return null;
              }

              // Per-request memoization without mutating WP_Post objects (avoids object-cache leaks).
              static $restValueCache = [];
              $postId = $postObj->ID ?? null;
              $cacheKey = $postId ? ($postId . ':' . $fieldName) : null;
              if ($cacheKey && array_key_exists($cacheKey, $restValueCache)) {
                return $restValueCache[$cacheKey];
              }

              $value = $_field->getValue($postObj);
              if ($cacheKey) {
                $restValueCache[$cacheKey] = $value;
              }
              return $value;
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