<?php
// Asset routes

// Searches through the list of assets
$app->get('/asset', function ($request, $response, $args) { global $frontend;
  $params = $request->getQueryParams();

  $category = '%';
  if(isset($frontend) && $frontend) {
    $category_type = $this->constants['category_type']['any'];
  } else {
    $category_type = $this->constants['category_type']['addon'];
  }
  $filter = '%';
  $username = '%';
  $order_column = 'rating';
  $order_direction = 'desc';
  $support_levels = [];
  $page_size = 10;
  $max_page_size = 500;
  $page_offset = 0;
  if(isset($params['category']) && $params['category'] != "") {
    $category = intVal($params['category']);
  }
  if(isset($params['type']) && isset($this->constants['category_type'][$params['type']])) {
    $category_type = $this->constants['category_type'][$params['type']];
  }
  if(isset($params['support'])) { // Expects the param like `support=community+testing` or `support[community]=1&support[testing]=1&...`
    $support_levels = [];
    if(is_array($params['support'])) {
      foreach($params['support'] as $key => $value) {
        if($value && isset($this->constants['support_level'][$key])) {
          array_push($support_levels, intVal($this->constants['support_level'][$key]));
        }
      }
    } else {
      foreach(explode(' ', $params['support']) as $key => $value) { // `+` is changed to ` ` automatically
        if(isset($this->constants['support_level'][$value])) {
          array_push($support_levels, intVal($this->constants['support_level'][$value]));
        }
      }
    }
  }
  if(isset($params['filter'])) {
    $filter = '%'.preg_replace('/[[:punct:]]+/', '%', $params['filter']).'%';
  }
  if(isset($params['user'])) {
    $username = $params['user'];
  }
  if(isset($params['max_results'])) {
    $page_size = min(abs(intVal($params['max_results']), $max_page_size));
  }
  if(isset($params['page'])) {
    $page_offset = abs(intVal($params['page'])) * $page_size;
  } elseif(isset($params['offset'])) {
    $page_offset = abs(intVal($params['offset']));
  }
  if(isset($params['sort'])) {
    $column_mapping = [
      'rating' => 'rating',
      'cost' => 'cost',
      'name' => 'title',
      'updated' => 'modify_date'
      // TODO: downloads
    ];
    if(isset($column_mapping[$params['sort']])) {
      $order_column = $column_mapping[$params['sort']];
    }
  }
  if(isset($params['reverse'])) {
    $order_direction = 'asc';
  }

  if(count($support_levels) === 0) {
    $support_levels = [0, 1, 2]; // Testing + Community + Official
  }
  $support_levels = implode('|', $support_levels);

  $query = $this->queries['asset']['search'];
  $query->bindValue(':category', $category);
  $query->bindValue(':category_type', $category_type, PDO::PARAM_INT);
  $query->bindValue(':support_levels_regex', $support_levels);
  $query->bindValue(':filter', $filter);
  $query->bindValue(':username', $username);
  $query->bindValue(':order', $order_column);
  $query->bindValue(':order_direction', $order_direction);
  $query->bindValue(':page_size', $page_size, PDO::PARAM_INT);
  $query->bindValue(':skip_count', $page_offset, PDO::PARAM_INT);
  $query->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query);
  if($error) return $response;

  $query_count = $this->queries['asset']['search_count'];
  $query_count->bindValue(':category', $category, PDO::PARAM_INT);
  $query_count->bindValue(':category_type', $category_type, PDO::PARAM_INT);
  $query_count->bindValue(':support_levels_regex', $support_levels);
  $query_count->bindValue(':filter', $filter);
  $query_count->bindValue(':username', $username);
  $query_count->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query_count);
  if($error) return $response;

  $total_count = $query_count->fetchAll()[0]['count'];

  $assets = $query->fetchAll();

  $context = $this;
  $assets = array_map(function($asset) use($context) {
    $asset["support_level"] = $context->constants['support_level'][intVal($asset['support_level']]);
    return $asset;
  }, $assets);

  return $response->withJson([
    'result' => $assets,
    'page' => floor($page_offset / $page_size),
    'pages' => ceil($total_count / $page_size),
    'page_length' => $page_size,
    'total_items' => intVal($total_count),
  ], 200);
});

// Get information for a single asset
$get_asset = function ($request, $response, $args) {
  $query = $this->queries['asset']['get_one'];

  $query->bindValue(':id', intVal($args['id']), PDO::PARAM_INT);
  $query->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query);
  if($error) return $response;

  if($query->rowCount() <= 0) {
    return $response->withJson([
      'error' => 'Couldn\'t find asset with id '.$args['id'].'!'
    ], 404);
  }

  $output = $query->fetchAll();
  $asset_info = [];
  $previews = [];

  foreach ($output as $row) {
    foreach ($row as $column => $value) {
      if($value!==null) {
        if($column==='preview_id') {
          $previews[] = ['preview_id' => $value];
        } elseif($column==="type" || $column==="link" || $column==="thumbnail") {
            $previews[count($previews) - 1][$column] = $value;
        } elseif($column==="category_type") {
          $asset_info["type"] = $this->constants['category_type'][intval($value)];
        } elseif($column==="support_level") {
          $asset_info["support_level"] = $this->constants['support_level'][intVal($value)];
        } elseif($column==="download_provider") {
          $asset_info["download_provider"] = $this->constants['download_provider'][intVal($value)];
        } else {
          $asset_info[$column] = $value;
        }
      }
    }
  }

  $asset_info['download_url'] = $this->utils->get_computed_download_url($asset_info['browse_url'], $asset_info['download_provider'], $asset_info['download_commit']);

  foreach ($previews as $i => $_) {
    if(!isset($previews[$i]['thumbnail']) || $previews[$i]['thumbnail'] == '') {
      if($previews[$i]['type'] == 'video') {
        $matches = [];
        if(preg_match('|youtube.com/watch\\?v=([^&]+)|', $previews[$i]['link'], $matches)) {
          $previews[$i]['thumbnail'] = 'http://img.youtube.com/vi/'.$matches[1].'/default.jpg';
        }
      } else {
        $previews[$i]['thumbnail'] = $previews[$i]['link'];
      }
    }
  }

  $asset_info['previews'] = $previews;

  return $response->withJson($asset_info, 200);
};
// Binding to multiple routes
$app->get('/asset/{id:[0-9]+}', $get_asset);
if(isset($frontend) && $frontend) {
  $app->get('/asset/{id:[0-9]+}/edit', $get_asset);
}

// Change support level of an asset
$app->post('/asset/{id:[0-9]+}/support_level', function ($request, $response, $args) {
  $body = $request->getParsedBody();

  $error = $this->utils->ensure_logged_in(false, $response, $body, $user);
  $error = $this->utils->error_reponse_if_not_user_has_level($error, $response, $user, 'moderator');
  $error = $this->utils->error_reponse_if_missing_or_not_string($error, $response, $body, 'support_level');
  if($error) return $response;
  if(!isset($this->constants['support_level'][$body['support_level']])) {
    $numeric_value_keys = [];
    foreach ($this->constants['support_level'] as $key => $value) {
      if(is_numeric($value)) {
        array_push($numeric_value_keys, $key);
      }
    }
    return $response->withJson([
      'error' => 'Invalid support level submitted, allowed are \'' . implode('\', \'', $numeric_value_keys) . '\'',
    ]);
  }

  $query = $this->queries['asset']['set_support_level'];

  $query->bindValue(':asset_id', intVal($args['id'], PDO::PARAM_INT));
  $query->bindValue(':support_level', intVal($this->constants['support_level'][$body['support_level']], PDO::PARAM_INT));

  $query->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query);
  if($error) return $response;

  return $response->withJson([
    'changed' => true,
    'url' => 'asset/' . $args['id'],
  ], 200);
});
