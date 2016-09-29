<?php
// ______                 __                     _______ _______ _______
//|   __ \.---.-.-----.--|  |.-----.----.---.-. |    ___|   |   |     __|
//|    __/|  _  |     |  _  ||  _  |   _|  _  | |    ___|       |__     |
//|___|   |___._|__|__|_____||_____|__| |___._| |___|   |__|_|__|_______|
//
// ============================================================================
// Copyright (c) 2007-2010 Artica Soluciones Tecnologicas, http://www.artica.es
// This code is NOT free software. This code is NOT licenced under GPL2 licence
// You cannnot redistribute it without written permission of copyright holder.
// ============================================================================

enterprise_include_once ('include/functions_pandora_networkmap.php');

function networkmap_delete_networkmap($id = 0) {
	if (enterprise_installed()) {
		// Relations
		$result = delete_relations($id);
		
		// Nodes
		$result = delete_nodes($id);
	}
	
	// Map
	$result = db_process_sql_delete('tmap', array('id' => $id));
	
	return $result;
}

function networkmap_process_networkmap($id = 0) {
	global $config;
	
	require_once ('include/functions_os.php');
	
	$numNodes = (int)db_get_num_rows('
		SELECT *
		FROM titem
		WHERE id_map = ' . $id . ';');
	
	$networkmap = db_get_row_filter('tmap',
		array('id' => $id));
	$filter = json_decode($networkmap['filter'], true);
	
	$pure = (int)get_parameter('pure', 0);
	
	switch ($networkmap['generation_method']) {
		case 0:
			$filter = "circo";
			$layout = "circular";
			break;
		case 1:
			$filter = "dot";
			$layout = "flat";
			break;
		case 2:
			$filter = "twopi";
			$layout = "radial";
			break;
		case 3:
			$filter = "neato";
			$layout = "neato";
			break;
		case 4:
			$filter = "neato";
			$layout = "spring1";
			break;
		case 5:
			$filter = "fdp";
			$layout = "spring2";
			break;
	}
	$simple = 0;
	$font_size = 12;
	$nooverlap = false;
	$zoom = 1;
	$ranksep = 2.5;
	$center = 0;
	$regen = 1;
	$show_snmp_modules = false;
	$dont_show_subgroups = false;
	
	$id_group = -666;
	$ip_mask = "";
	switch ($networkmap['source']) {
		case 0:
			$id_group = $networkmap['id_group'];
			break;
		case 1:
			$recon_task = db_get_row_filter('trecon_task',
				array('id_rt' => $networkmap['source_data']));
			
			$ip_mask = $recon_task['field1'];
			break;
	}
	
	$nodes_and_relations = array();
	
	if (enterprise_installed() && ($numNodes > 0)) {
		$nodes_and_relations = get_structure_nodes($id);
	}
	else {
		// Generate dot file
		$graph = networkmap_generate_dot (__('Pandora FMS'),
			$id_group,
			$simple,
			$font_size,
			$layout,
			$nooverlap,
			$zoom,
			$ranksep,
			$center,
			$regen,
			$pure,
			$id,
			$show_snmp_modules,
			false, //cut_names
			true, // relative
			'',
			$ip_mask,
			$dont_show_subgroups,
			false,
			null,
			$old_mode);
		
		$filename_dot = sys_get_temp_dir() . "/networkmap_" . $filter;
		if ($simple) {
			$filename_dot .= "_simple";
		}
		if ($nooverlap) {
			$filename_dot .= "_nooverlap";
		}
		$filename_dot .= "_" . $id . ".dot";
		
		file_put_contents($filename_dot, $graph);
		
		$filename_plain = sys_get_temp_dir() . "/plain.txt";
		
		$cmd = "$filter -Tplain -o " . $filename_plain . " " .
			$filename_dot;
		
		system ($cmd);
		
		unlink($filename_dot);
		
		$nodes = networkmap_loadfile($id, $filename_plain,
			$relation_nodes, $graph);
		
		//Set the position of modules
		foreach ($nodes as $key => $node) {
			if ($node['type'] == 'module') {
				//Search the agent of this module for to get the
				//position
				foreach ($nodes as $key2 => $node2) {
					if ($node2['id_agent'] != 0 && $node2['type'] == 'agent') {
						if ($node2['id_agent'] == $node['id_agent']) {
							$nodes[$key]['coords'][0] =
								$nodes[$key2]['coords'][0] + $node['height'] / 2;
							$nodes[$key]['coords'][1] =
								$nodes[$key2]['coords'][1] + $node['width'] / 2;
						}
					}
				}
			}
		}
		
		unlink($filename_plain);
		
		$nodes_and_relations['nodes'] = array();
		$index = 0;
		foreach ($nodes as $key => $node) {
			$nodes_and_relations['nodes'][$index]['id_map'] = $id;
			
			$nodes_and_relations['nodes'][$index]['x'] = (int)$node['coords'][0];
			$nodes_and_relations['nodes'][$index]['y'] = (int)$node['coords'][1];
			
			if (($node['type'] == 'agent') || ($node['type'] == '')) {
				$nodes_and_relations['nodes'][$index]['source_data'] = $node['id_agent'];
				$nodes_and_relations['nodes'][$index]['type'] = 0;
			}
			else {
				$nodes_and_relations['nodes'][$index]['source_data'] = $node['id_module'];
				$nodes_and_relations['nodes'][$index]['id_agent'] = $node['id_agent'];
				$nodes_and_relations['nodes'][$index]['type'] = 1;
			}
			
			$style = array();
			$style['shape'] = 'circle';
			$style['image'] = $node['image'];
			$style['width'] = $node['width'];
			$style['height'] = $node['height'];
			$style['label'] = $node['text'];
			$nodes_and_relations['nodes'][$index]['style'] = json_encode($style);
			
			$index++;
		}
		
		$nodes_and_relations['relations'] = array();
		$index = 0;
		foreach ($relation_nodes as $relation) {
			$nodes_and_relations['relations'][$index]['id_map'] = $id;
			
			if (($relation['parent_type'] == 'agent') || ($relation['parent_type'] == '')) {
				$nodes_and_relations['relations'][$index]['id_parent'] = $relation['id_parent'];
				$nodes_and_relations['relations'][$index]['id_parent_source_data'] = $nodes[$relation['id_parent']]['id_agent'];
				$nodes_and_relations['relations'][$index]['parent_type'] = 0;
			}
			else if ($relation['parent_type'] == 'module') {
				$nodes_and_relations['relations'][$index]['id_parent'] = $relation['id_parent'];
				$nodes_and_relations['relations'][$index]['id_parent_source_data'] = $nodes[$relation['id_parent']]['id_module'];
				$nodes_and_relations['relations'][$index]['parent_type'] = 1;
			}
			
			if (($relation['child_type'] == 'agent') || ($relation['child_type'] == '')) {
				$nodes_and_relations['relations'][$index]['id_child'] = $relation['id_child'];
				$nodes_and_relations['relations'][$index]['id_child_source_data'] = $nodes[$relation['id_child']]['id_agent'];
				$nodes_and_relations['relations'][$index]['child_type'] = 0; 
			}
			else if ($relation['child_type'] == 'module') {
				$nodes_and_relations['relations'][$index]['id_child'] = $relation['id_child'];
				$nodes_and_relations['relations'][$index]['id_child_source_data'] = $nodes[$relation['id_child']]['id_module'];
				$nodes_and_relations['relations'][$index]['child_type'] = 1; 
			}
			
			$index++;
		}
		
		$center = array('x' => 500, 'y' => 500);
		
		if (enterprise_installed()) {
			enterprise_include_once("include/functions_pandora_networkmap.php");
			$center = save_generate_nodes($id, $nodes_and_relations);
		}
		
		$networkmap['center_x'] = $center['x'];
		$networkmap['center_y'] = $center['y'];
		db_process_sql_update('tmap',
			array('center_x' => $networkmap['center_x'], 'center_y' => $networkmap['center_y']),
			array('id' => $id));
	}
	
	return $nodes_and_relations;
}

function get_networkmaps($id) {
	$groups = array_keys(users_get_groups(null, "IW"));
	
	$filter = array();
	$filter['id_group'] = $groups;
	$filter['id'] = '<>' . $id;
	$networkmaps = db_get_all_rows_filter('tmap',$filter);
	if ($networkmaps === false)
		$networkmaps = array();
	
	$return = array();
	$return[0] = __('None');
	foreach ($networkmaps as $networkmap) {
		$return[$networkmap['id']] = $networkmap['name'];
	}
	
	return $return;
}

function networkmap_db_node_to_js_node($node, &$count, &$count_item_holding_area) {
	global $config;
	
	$networkmap = db_get_row('tmap', 'id', $node['id_map']);
	
	$networkmap['filter'] = json_decode($networkmap['filter'], true);
	
	//Hardcoded
	$networkmap['filter']['holding_area'] = array(500, 500);
	
	//40 = DEFAULT NODE RADIUS
	//30 = for to align
	$holding_area_max_y = $networkmap['height'] +
		30 + 40 * 2 - $networkmap['filter']['holding_area'][1] + 
		10 * 40;
	
	$item = array();
	$item['id'] = $count;
	$item['id_db'] = (int)$node['id'];
	if ((int)$node['type'] == 0) {
		$item['type'] = 0;
		$item['id_agent'] = (int)$node['source_data'];
		$item['id_module'] = "";
	}
	else if ((int)$node['type'] == 1) {
		$item['type'] = 1;
		$item['id_agent'] = (int)$node['style']['id_agent'];
		$item['id_module'] = (int)$node['source_data'];
	}
	
	$item['fixed'] = true;
	$item['x'] = (int)$node['x'];
	$item['y'] = (int)$node['y'];
	$item['z'] = (int)$node['z'];
	$item['state'] = $node['state'];
	if ($item['state'] == 'holding_area') {
		//40 = DEFAULT NODE RADIUS
		//30 = for to align
		$holding_area_x = $networkmap['width'] +
			30 + 40 * 2 - $networkmap['filter']['holding_area'][0]
			+ ($count_item_holding_area % 11) * 40;
		$holding_area_y = $networkmap['height'] +
			30 + 40 * 2 - $networkmap['filter']['holding_area'][1]
			+ (int)(($count_item_holding_area / 11)) * 40;
		
		if ($holding_area_max_y <= $holding_area_y) {
			$holding_area_y = $holding_area_max_y;
		}
		
		$item['x'] = $holding_area_x;
		$item['y'] = $holding_area_y;
		
		//Increment for the next node in holding area
		$count_item_holding_area++;
	}
	
	$item['image_url'] = "";
	$item['image_width'] = 0;
	$item['image_height'] = 0;
	if (!empty($node['style']['image'])) {
		$item['image_url'] = html_print_image(
			$node['style']['image'], true, false, true);
		$image_size = getimagesize(
			$config['homedir'] . '/' . $node['style']['image']);
		$item['image_width'] = (int)$image_size[0];
		$item['image_height'] = (int)$image_size[1];
	}
	$item['text'] = io_safe_output($node['style']['label']);
	$item['shape'] = $node['style']['shape'];
	switch ($node['type']) {
		case 0:
			$color = get_status_color_networkmap($node['source_data']);
			break;
		default:
			//Old code
			if ($node['source_data'] == -1) {
				$color = "#364D1F";
			}
			else if ($node['source_data'] == -2) {
				$color = "#364D1F";
			}
			else {
				$color = get_status_color_networkmap($node['source_data']);
			}
			break;
	}
	$item['color'] = $color;
	$item['map_id'] = 0;
	if (isset($node['id_map'])) {
		$item['map_id'] = $node['id_map'];
	}
	
	$count++;
	
	return $item;
}

function get_status_color_networkmap($id, $color = true) {
	$status = agents_get_status($id);
	
	if (!$color) {
		return $status;
	}
	// Set node status
	switch($status) {
		case 0: 
			$status_color = COL_NORMAL; // Normal monitor
			break;
		case 1:
			$status_color = COL_CRITICAL; // Critical monitor
			break;
		case 2:
			$status_color = COL_WARNING; // Warning monitor
			break;
		case 4:
			$status_color = COL_ALERTFIRED; // Alert fired
			break;
		default:
			$status_color = COL_UNKNOWN; // Unknown monitor
			break;
	}
	
	return $status_color;
}

function networkmap_clean_relations_for_js(&$relations) {
	do {
		$cleaned = true;
		
		foreach ($relations as $key => $relation) {
			if ($relation['id_parent_source_data'] == $relation['id_child_source_data']) {
				$cleaned = false;
				
				if ($relation['parent_type'] == 1) {
					$to_find = $relation['id_parent_source_data'];
					$to_replace = $relation['id_child_source_data'];
				}
				elseif ($relation['child_type'] == 1) {
					$to_find = $relation['id_child_source_data'];
					$to_replace = $relation['id_parent_source_data'];
				}
				
				//Replace and erase the links
				foreach ($relations as $key2 => $relation2) {
					if ($relation2['id_parent_source_data'] == $to_find) {
						$relations[$key2]['id_parent_source_data'] = $to_replace;
					}
					elseif ($relation2['id_child_source_data'] == $to_find) {
						$relations[$key2]['id_child_source_data'] = $to_replace;
					}
				}
				
				unset($relations[$key]);
				
				break;
			}
		}
	}
	while (!$cleaned);
}

function networkmap_links_to_js_links($relations, $nodes_graph) {
	$return = array();
	
	if (enterprise_installed()) {
		enterprise_include_once('include/functions_pandora_networkmap.php');
	}
	
	foreach ($relations as $relation) {
		if (($relation['parent_type'] == 1) && ($relation['child_type'] == 1)) {
			$id_target_agent = agents_get_agent_id_by_module_id($relation['id_parent_source_data']);
			$id_source_agent = agents_get_agent_id_by_module_id($relation['id_child_source_data']);
			$id_target_module = $relation['id_parent_source_data'];
			$id_source_module = $relation['id_child_source_data'];
		}
		else if (($relation['parent_type'] == 1) && ($relation['child_type'] == 0)) {
			$id_target_agent = $relation['id_parent_source_data'];
			$id_source_module = $relation['id_child_source_data'];
		}
		else {
			$id_target_agent = $relation['id_parent_source_data'];
			$id_source_agent = $relation['id_child_source_data'];
		}
		
		$item = array();
		if (enterprise_installed()) {
			$item['id_db'] = get_relation_id($relation);
		}
		else {
			$item['id_db'] = $relation['id'];
		}
		$item['arrow_start'] = '';
		$item['arrow_end'] = '';
		$item['status_start'] = '';
		$item['status_end'] = '';
		$item['id_module_start'] = 0;
		$item['id_agent_start'] = (int)$id_source_agent;
		$item['id_module_end'] = 0;
		$item['id_agent_end'] = (int)$id_target_agent;
		$item['target'] = -1;
		$item['source'] = -1;
		
		if (enterprise_installed()) {
			$target_and_source = array();
			$target_and_source = get_id_target_and_source_in_db($relation);
			$item['target_id_db'] = (int)$target_and_source['target'];
			$item['source_id_db'] = (int)$target_and_source['source'];
		}
		else {
			if (($relation['parent_type'] == 1) && ($relation['child_type'] == 1)) {
				$item['target_id_db'] = $id_target_agent;
				$item['source_id_db'] = $id_source_agent;
			}
			else if (($relation['parent_type'] == 0) && ($relation['child_type'] == 0)) {
				$item['target_id_db'] = (int)$relation['id_parent_source_data'];
				$item['source_id_db'] = $id_source_agent;
			}
			else {
				$item['target_id_db'] = (int)$relation['id_parent_source_data'];
				$item['source_id_db'] = (int)$relation['id_child_source_data'];
			}
		}
		
		$item['text_end'] = "";
		$item['text_start'] = "";
		
		if ($relation['parent_type'] == 1) {
			$item['arrow_end'] = 'module';
			$item['status_end'] = modules_get_agentmodule_status((int)$id_target_module, false, false, null);
			$item['id_module_end'] = (int)$id_target_module;
			$item['text_end'] = io_safe_output(modules_get_agentmodule_name((int)$id_target_module));
		}
		if ($relation['child_type'] == 1) {
			$item['arrow_start'] = 'module';
			$item['status_start'] = modules_get_agentmodule_status((int)$id_source_module, false, false, null);
			$item['id_module_start'] = (int)$id_source_module;
			$item['text_start'] = io_safe_output(modules_get_agentmodule_name((int)$id_source_module));
		}
		
		$agent = 0;
		$agent2 = 0;
		if (($relation['parent_type'] == 1) && ($relation['child_type'] == 1)) {
			$agent = agents_get_agent_id_by_module_id($relation['id_parent_source_data']);
			$agent2 = agents_get_agent_id_by_module_id($relation['id_child_source_data']);
		}
		else if ($relation['child_type'] == 1) {
			$agent = $relation['id_parent_source_data'];
			$agent2 = agents_get_agent_id_by_module_id($relation['id_child_source_data']);
		}
		else {
			$agent = $relation['id_parent_source_data'];
			$agent2 = $relation['id_child_source_data'];
			
		}
		
		foreach ($nodes_graph as $node) {
			if ($node['id_agent'] == $agent) {
				$item['target'] = $node['id'];
			}
			else if ($node['id_agent'] == $agent2) {
				$item['source'] = $node['id'];
			}
		}
		
		$return[] = $item;
	}
	
	return $return;
}

function networkmap_write_js_array($id, $nodes_and_relations = array()) {
	global $config;
	
	db_clean_cache();
	
	$ent_installed = (int)enterprise_installed();
	
	$networkmap = db_get_row('tmap', 'id', $id);
	
	$networkmap['filter'] = json_decode($networkmap['filter'], true);
	
	//Hardcoded
	$networkmap['filter']['holding_area'] = array(500, 500);
	
	echo "\n";
	echo "////////////////////////////////////////////////////////////////////\n";
	echo "// VARS FROM THE DB\n";
	echo "////////////////////////////////////////////////////////////////////\n";
	echo "\n";
	echo "var url_background_grid = '" . ui_get_full_url(
		'images/background_grid.png') . "'\n";
	echo "var url_popup_pandora = '" . ui_get_full_url(
		'operation/agentes/pandora_networkmap.popup.php') . "'\n";
	echo "var networkmap_id = " . $id . ";\n";
	echo "var networkmap_refresh_time = 1000 * " .
		$networkmap['source_period'] . ";\n";
	echo "var networkmap_center = [ " .
		$networkmap['center_x'] . ", " .
		$networkmap['center_y'] . "];\n";
	echo "var networkmap_dimensions = [ " .
		$networkmap['width'] . ", " .
		$networkmap['height'] . "];\n";
		
	echo "var enterprise_installed = " . $ent_installed . ";\n";
	
	echo "var networkmap_holding_area_dimensions = " .
		json_encode($networkmap['filter']['holding_area']) . ";\n";
	
	echo "var networkmap = {'nodes': [], 'links':  []};\n";
	
	$nodes = $nodes_and_relations['nodes'];
	
	if (empty($nodes))
		$nodes = array();
	
	$count_item_holding_area = 0;
	$count = 0;
	$nodes_graph = array();
	
	foreach ($nodes as $key => $node) {
		$style = json_decode($node['style'], true);
		$node['style'] = json_decode($node['style'], true);
		
		// Only agents can be show
		if (isset($node['type'])) {
			if ($node['type'] == 1)
				continue;
		}
		else {
			$node['type'] = '';
		}
		
		$item = networkmap_db_node_to_js_node(
			$node, $count, $count_item_holding_area);
		
		echo "networkmap.nodes.push(" . json_encode($item) . ");\n";
		$nodes_graph[$item['id']] = $item;
	}
	
	$relations = $nodes_and_relations['relations'];
	
	if ($relations === false) $relations = array();
	//Clean the relations and transform the module relations into
	//interfaces
	networkmap_clean_relations_for_js($relations);
	
	$links_js = networkmap_links_to_js_links($relations, $nodes_graph);
	
	foreach ($links_js as $link_js) {
		if ($link_js['target'] == -1)
			continue;
		if ($link_js['source'] == -1)
			continue;
		if ($link_js['target'] == $link_js['source']) 
			continue;
		echo "networkmap.links.push(" . json_encode($link_js) . ");\n";
	}
	
	echo "\n";
	echo "\n";
	
	echo "////////////////////////////////////////////////////////////////////\n";
	echo "// INTERFACE STATUS COLORS\n";
	echo "////////////////////////////////////////////////////////////////////\n";
	
	$module_color_status = array();
	$module_color_status[] = array(
		'status_code' => AGENT_MODULE_STATUS_NORMAL,
		'color' => COL_NORMAL);
	$module_color_status[] = array(
		'status_code' => AGENT_MODULE_STATUS_CRITICAL_BAD,
		'color' => COL_CRITICAL);
	$module_color_status[] = array(
		'status_code' => AGENT_MODULE_STATUS_WARNING,
		'color' => COL_WARNING);
	$module_color_status[] = array(
		'status_code' => AGENT_STATUS_ALERT_FIRED,
		'color' => COL_ALERTFIRED);
	$module_color_status_unknown = COL_UNKNOWN;
	
	echo "var module_color_status = " .
		json_encode($module_color_status) . ";\n";
	echo "var module_color_status_unknown = '" .
		$module_color_status_unknown . "';\n";
	
	echo "\n";
	echo "\n";
	
	echo "////////////////////////////////////////////////////////////////////\n";
	echo "// Other vars\n";
	echo "////////////////////////////////////////////////////////////////////\n";
	
	echo "var translation_none = '" . __('None') . "';\n";
	echo "var dialog_node_edit_title = '" . __('Edit node %s') . "';\n";
	echo "var holding_area_title = '" . __('Holding Area') . "';\n";
	echo "var show_details_menu = '" . __('Show details') . "';\n";
	echo "var edit_menu = '" . __('Edit') . "';\n";
	echo "var set_as_children_menu = '" . __('Set as children') . "';\n";
	echo "var set_parent_menu = '" . __('Set parent') . "';\n";
	echo "var abort_relationship_menu = '" . __('Abort the action of set relationship') . "';\n";
	echo "var delete_menu = '" . __('Delete') . "';\n";
	echo "var add_node_menu = '" . __('Add node') . "';\n";
	echo "var set_center_menu = '" . __('Set center') . "';\n";
	echo "var refresh_menu = '" . __('Refresh') . "';\n";
	echo "var refresh_holding_area_menu = '" . __('Refresh Holding area') . "';\n";
	echo "var abort_relationship_menu = '" . __('Abort the action of set relationship') . "';\n";
	
	echo "\n";
	echo "\n";
}

function networkmap_loadfile($id = 0, $file = '',
	&$relations_param, $graph) {
	global $config;
	
	$height_map = db_get_value('height', 'tmap', 'id', $id);
	
	$networkmap_nodes = array();
	
	$relations = array();
	
	$other_file = file($file);
	
	//Remove the graph head
	$graph = preg_replace('/^graph .*/', '', $graph);
	//Cut in nodes the graph
	$graph = explode("]", $graph);
	
	$ids = array();
	foreach ($graph as $node) {
		$line = str_replace("\n", " ", $node);
		
		if (preg_match('/([0-9]+) \[.*tooltip.*id_module=([0-9]+)/', $line, $match) != 0) {
			$ids[$match[1]] = array(
				'type' => 'module',
				'id_module' => $match[2]
				);
		}
		else if (preg_match('/([0-9]+) \[.*tooltip.*id_agent=([0-9]+)/', $line, $match) != 0) {
			$ids[$match[1]] = array(
				'type' => 'agent',
				'id_agent' => $match[2]
				);
		}
	}
	
	foreach ($other_file as $key => $line) {
		//clean line a long spaces for one space caracter
		$line = preg_replace('/[ ]+/', ' ', $line);
		
		$data = array();
		
		if (preg_match('/^node.*$/', $line) != 0) {
			$items = explode(' ', $line);
			$node_id = $items[1];
			$node_x = $items[2] * 100; //200 is for show more big
			$node_y = $height_map - $items[3] * 100; //200 is for show more big
			$data['text'] = '';
			$data['image'] = '';
			$data['width'] = 10;
			$data['height'] = 10;
			$data['id_agent'] = 0;
			
			if (preg_match('/<img src=\"([^\"]*)\"/', $line, $match) == 1) {
				$image = $match[1];
				
				$data['shape'] = 'image';
				$data['image'] = $image;
				$size = getimagesize($config['homedir'] . '/' . $image);
				$data['width'] = $size[0];
				$data['height'] = $size[1];
				
				$data['id_agent'] = 0;
				$data['id_module'] = 0;
				$data['type'] = '';
				if (preg_match('/Pandora FMS/', $line) != 0) {
					$data['text'] = 'Pandora FMS';
					$data['id_agent'] = -1;
				}
				else {
					$data['type'] = $ids[$node_id]['type'];
					
					switch ($ids[$node_id]['type']) {
						case 'module':
							$data['id_module'] = $ids[$node_id]['id_module'];
							$data['id_agent'] =
								modules_get_agentmodule_agent($ids[$node_id]['id_module']);
							
							$text = modules_get_agentmodule_name($data['id_module']);
							$text = io_safe_output($text);
							$text = ui_print_truncate_text($text,
								'agent_medium', false, true, false,
								'...', false);
							$data['text'] = $text;
							$data['id_agent'] = db_get_value("id_agente", "tagente_modulo", "id_agente_modulo", $data['id_module']);
							break;
						case 'agent':
							$data['id_agent'] = $ids[$node_id]['id_agent'];
							
							$text = agents_get_name($ids[$node_id]['id_agent']);
							$text = io_safe_output($text);
							$text = ui_print_truncate_text($text,
								'agent_medium', false, true, false,
								'...', false);
							$data['text'] = $text;
							$data['parent'] = db_get_value("id_parent", "tagente", "id_agente", $data['id_agent']);
							break;
					}
				}
			}
			else {
				$data['shape'] = 'wtf';
			}
			
			$data['coords'] = array($node_x, $node_y);
			
			if (strpos($node_id, "transp_") !== false) {
				//removed the transparent nodes
			}
			else {
				$networkmap_nodes[$node_id] = $data;
			}
		}
		else if (preg_match('/^edge.*$/', $line) != 0) {
			$items = explode(' ', $line);
			$line_orig = $items[2];
			$line_dest = $items[1];
			
			//$relations[$line_dest] = $line_orig;
			$relations[] = array('orig' => $line_orig, 'dest' => $line_dest);
		}
	}
	
	$relations_param = array();
	
	foreach ($relations as $rel) {
		if (strpos($rel['orig'], "transp_") !== false) {
			//removed the transparent nodes
			continue;
		}
		if (strpos($rel['dest'], "transp_") !== false) {
			//removed the transparent nodes
			continue;
		}
		
		$row = array(
			'id_child' => $rel['orig'],
			'child_type' => $networkmap_nodes[$rel['orig']]['type'],
			'id_parent' => $rel['dest'],
			'parent_type' => $networkmap_nodes[$rel['dest']]['type']);
		$relations_param[] = $row;
	}
	
	return $networkmap_nodes;
}

function show_node_info($id_node, $refresh_state, $user_readonly, $id_agent) {
	global $config;
	
	echo "<script type='text/javascript' src='../../include/javascript/functions_pandora_networkmap.js'></script>";
	
	if (enterprise_installed()) {
		$row = get_node_from_db($id_node);
	}
	else {
		$row['source_data'] = $id_agent;
	}
	
	$style = json_decode($row['style'], true);
	
	if (($row['source_data']) == -2 && (enterprise_installed())) {
		//Show the dialog to edit the fictional point.
		if ($user_readonly) {
			require ($config["homedir"]."/general/noaccess.php");
			return;
		}
		else {
			$networkmaps = get_networkmaps($row['id_map']);
				
			$selectNetworkmaps = html_print_select($networkmaps,
				'networmaps_enterprise', $style['networkmap'], '', '', 0, true);
			
			$shapes = array(
				'circle' => __('Circle'),
				'square' => __('Square'),
				'rhombus' => __('Rhombus'));
			
			$mini_form_fictional_point = "<table cellpadding='2'>
				<tr>" .
					"<td>" . __('Name') ."<td>". html_print_input_text('fictional_name', $style['label'], '', 25, 255, true) . 
				'<td>' .__('Shape') . "<td>". html_print_select($shapes, 'fictional_shape', 0, '', '', 0, true) . "</td></tr><tr><td>".
				__('Radius') . "<td>". '<input type="text" size="3" maxlength="3" value="' . ($style['width'] / 2) . '" id="fictional_radious" />' . "<td>" .
				__('Color') . "<td>" .
				'<input type="text" size="7" value="' . $style['color'] . '" id="fictional_color" class="fictional_color"/> <tr />' 
				."<tr><td>".__("Network map linked"). "<td>".$selectNetworkmaps.
				"<td align=right>". html_print_button(__('Update'), 'update_fictional', false, 'update_fictional_node_popup(' . $id_node . ');', 'class="sub next"', true) . "</tr></table>";
			
			echo $mini_form_fictional_point;
			
			
			echo '
				<script type="text/javascript">
					$(document).ready(function () {
						$(".fictional_color").attachColorPicker();
					});
				</script>';
			
			return;
		}
	} 
	else {
		//Show the view of node.
		$url_agent = ui_get_full_url(false);
		$url_agent .= 'index.php?' .
			'sec=estado&' .
			'sec2=operation/agentes/ver_agente&' .
			'id_agente=' . $row['source_data'];
		
		$modules = agents_get_modules($row['source_data'],
			array('nombre', 'id_tipo_modulo'), array('disabled' => 0),
			true, false);
		if ($modules == false) {
			$modules = array();
		}
		$count_module = count($modules);
		
		$snmp_modules = agents_get_modules($row['source_data'],
			array('nombre', 'id_tipo_modulo'),
			array('id_tipo_modulo' => 18, 'disabled' => 0), true, false);
		$count_snmp_modules = count($snmp_modules);
		
		echo "<script type='text/javascript'>";
		echo "var node_info_height = 0;";
		echo "var node_info_width = 0;";
		echo "var count_snmp_modules = " . $count_snmp_modules . ";";
		echo "var module_count = " . $count_module . ";";
		echo "var modules = [];";
		foreach ($modules as $id_agent_module => $module) {
			$text = io_safe_output($module['nombre']);
			$sort_text = ui_print_truncate_text($text, 'module_small', false, true, false, '...');
			//$text = $sort_text;
			
			$color_status = get_status_color_module_networkmap($id_agent_module);
			
			echo "modules[" . $id_agent_module . "] = {
					'pos_x': null,
					'pos_y': null ,
					'text': '" . $text . "',
					'short_text': '" . $sort_text . "',
					'type': " . $module['id_tipo_modulo'] . ",
					'status_color': '" . $color_status . "'
					};";
		}
		
		echo "var color_status_node = '" . get_status_color_networkmap($row['source_data']) . "';";
		echo "</script>";
		
		$mode_show = get_parameter('mode_show', 'all');
		echo "<script type='text/javascript'>
			var mode_show = '$mode_show';
		</script>";
		
		echo '<div style="text-align: center;">';
		echo '<b><a target="_blank" style="text-decoration: none;" href="' . $url_agent . '">' . agents_get_name($row['source_data']) . '</a></b><br />';
		$modes_show = array('status_module' => 'Only status', 'all' => 'All');
		echo __('Show modules:');
		html_print_select($modes_show, 'modes_show', $mode_show, 'refresh_window();');
		echo " ";
		html_print_button('Refresh', 'refresh_button', false, 'refresh_window();',
			'style="padding-left: 10px; padding-right: 10px;"');
		echo '</div>';
		echo '<div id="content_node_info" style="width: 100%; height: 90%;
			overflow: auto; text-align: center;">
			<canvas id="node_info" style="background: #fff;">
					Use a browser that support HTML5.
			</canvas>';
		
		echo '
			<script type="text/javascript">
				function refresh_window() {
					url = location.href
					
					mode = $("#modes_show option:selected").val();
					
					url = url + "&mode_show=" + mode;
					
					window.location.replace(url);
				}
				
				$(document).ready(function () {
					node_info_height = $("#content_node_info").height();
					node_info_width = $("#content_node_info").width();
					
					//Set the first size for the canvas
					//$("#node_info").attr("height", $(window).height());
					//$("#node_info").attr("width", $(window).width());
					show_networkmap_node(' . $row['source_data'] . ', ' . $refresh_state . ');
				});
			</script>
			</div>
		';
		echo "<div id='tooltip' style='border: 1px solid black; background: white; position: absolute; display:none;'></div>";
	}
}

function get_status_color_module_networkmap($id_agente_modulo) {
	$status = modules_get_agentmodule_status($id_agente_modulo);
	
	// Set node status
	switch($status) {
		case 0:
		//At the moment the networkmap enterprise does not show the
		//alerts.
		case AGENT_MODULE_STATUS_NORMAL_ALERT:
			$status_color = COL_NORMAL; // Normal monitor
			break;
		case 1:
			$status_color = COL_CRITICAL; // Critical monitor
			break;
		case 2:
			$status_color = COL_WARNING; // Warning monitor
			break;
		case 4:
			$status_color = COL_ALERTFIRED; // Alert fired
			break;
		default:
			$status_color = COL_UNKNOWN; // Unknown monitor
			break;
	}
	
	return $status_color;
}

function duplicate_networkmap($id) {
	$return = true;
	
	$values = db_get_row('tmap', 'id', $id);
	unset($values['id']);
	$free_name = false;
	$values['name'] = io_safe_input(__('Copy of ') . io_safe_output($values['name']));
	$count = 1;
	while (!$free_name) {
		$exist = db_get_row_filter('tmap', array('name' => $values['name']));
		if ($exist === false) {
			$free_name = true;
		}
		else {
			$values['name'] = $values['name'] . io_safe_input(' ' . $count);
		}
	}
	
	$correct_or_id = db_process_sql_insert('tmap', $values);
	if ($correct_or_id === false) {
		$return = false;
	}
	else {
		if (enterprise_installed()) {
			$new_id = $correct_or_id;
			duplicate_map_insert_nodes_and_relations($id, $new_id);
		}
	}
	
	if ($return) {
		return true;
	}
	else {
		//Clean DB.
		if (enterprise_installed()) {
			// Relations
			delete_relations($new_id);
			
			// Nodes
			delete_nodes($new_id);
		}
		db_process_sql_delete('tmap', array('id' => $new_id));
		
		return false;
	}
}

function clean_duplicate_links ($relations) {
	if (enterprise_installed()) {
		enterprise_include_once('include/functions_pandora_networkmap.php');
	}
	$segregation_links = array();
	$index = 0;
	$index2 = 0;
	$index3 = 0;
	foreach ($relations as $rel) {
		if (($rel['parent_type'] == 0) && ($rel['child_type'] == 0)) {
			$segregation_links['aa'][$index] = $rel;
			$index++;
		}
		else if (($rel['parent_type'] == 1) && ($rel['child_type'] == 1)) {
			$segregation_links['mm'][$index2] = $rel;
			$index2++;
		}
		else {
			$segregation_links['am'][$index3] = $rel;
			$index3++;
		}
	}
	
	$final_links = array();
	/* ---------------------------------------------------------------- */
	/* --------------------- Clean duplicate links -------------------- */
	/* ---------------------------------------------------------------- */
	$duplicated = false;
	$index_to_del = 0;
	$index = 0;
	foreach ($segregation_links['aa'] as $link) {
		foreach ($segregation_links['aa'] as $link2) {
			if ($link['id_parent'] == $link2['id_child'] && $link['id_child'] == $link2['id_parent']) {
				if (enterprise_installed()) {
					delete_link($segregation_links['aa'][$index_to_del]);
				}
				unset($segregation_links['aa'][$index_to_del]);
			}
			$index_to_del++;
		}
		$final_links['aa'][$index] = $link;
		$index++;
		
		$duplicated = false;
		$index_to_del = 0;
	}
	
	$duplicated = false;
	$index_to_del = 0;
	$index2 = 0;
	foreach ($segregation_links['mm'] as $link) {
		foreach ($segregation_links['mm'] as $link2) {
			if ($link['id_parent'] == $link2['id_child'] && $link['id_child'] == $link2['id_parent']) {
				if (enterprise_installed()) {
					delete_link($segregation_links['mm'][$index_to_del]);
				}
				unset($segregation_links['mm'][$index_to_del]);
			}
			$index_to_del++;
		}
		$final_links['mm'][$index2] = $link;
		$index2++;
		
		$duplicated = false;
		$index_to_del = 0;
	}
	
	$final_links['am'] = $segregation_links['am'];
	
	/* ---------------------------------------------------------------- */
	/* ----------------- AA, AM and MM links management --------------- */
	/* ------------------ Priority: ----------------------------------- */
	/* -------------------- 1 -> MM (module - module) ----------------- */
	/* -------------------- 2 -> AM (agent - module) ------------------ */
	/* -------------------- 3 -> AA (agent - agent) ------------------- */
	/* ---------------------------------------------------------------- */
	$final_links2 = array();
	$index = 0;
	$l3_link = array();
	$agent1 = 0;
	$agent2 = 0;
	foreach ($final_links['mm'] as $rel_mm) {
		$module_parent = $rel_mm['id_parent_source_data'];
		$module_children = $rel_mm['id_child_source_data'];
		$agent1 = (int)agents_get_agent_id_by_module_id($module_parent);
		$agent2 = (int)agents_get_agent_id_by_module_id($module_children);
		foreach ($final_links['aa'] as $key => $rel_aa) {
			$l3_link = $rel_aa;
			$id_p_source_data = (int)$rel_aa['id_parent_source_data'];
			$id_c_source_data = (int)$rel_aa['id_child_source_data'];
			if ((($id_p_source_data == $agent1) && ($id_c_source_data == $agent2)) || 
				(($id_p_source_data == $agent2) && ($id_c_source_data == $agent1))) {
				
				if (enterprise_installed()) {
					delete_link($final_links['aa'][$key]);
				}
				unset($final_links['aa'][$key]);
			}
		}
	}
	
	$final_links2['aa'] = $final_links['aa'];
	$final_links2['mm'] = $final_links['mm'];
	$final_links2['am'] = $final_links['am'];
	
	$same_m = array();
	$index = 0;
	foreach ($final_links2['am'] as $rel_am) {
		foreach ($final_links2['am'] as $rel_am2) {
			if (($rel_am['id_child_source_data'] == $rel_am2['id_child_source_data']) && 
				($rel_am['id_parent_source_data'] != $rel_am2['id_parent_source_data'])) {
				$same_m[$index]['rel'] = $rel_am2;
				$same_m[$index]['agent_parent'] = $rel_am['id_parent_source_data'];
				$index++;
			}
		}
	}
	
	$final_links3 = array();
	$index = 0;
	$l3_link = array();
	$have_l3 = false;
	foreach ($final_links2['aa'] as $key => $rel_aa) {
		$l3_link = $rel_aa;
		foreach ($same_m as $rel_am) {
			if ((($rel_aa['id_parent_source_data'] == $rel_am['parent']['id_parent_source_data']) && 
				($rel_aa['id_child_source_data'] == $rel_am['rel']['id_parent_source_data'])) || 
				(($rel_aa['id_child_source_data'] == $rel_am['parent']['id_parent_source_data']) && 
					($rel_aa['id_parent_source_data'] == $rel_am['rel']['id_parent_source_data']))) {
						
				$have_l3 = true;
				
				if (enterprise_installed()) {
					delete_link($final_links2['aa'][$key]);
				}
			}
		}
		if (!$have_l3) {
			$final_links3['aa'][$index] = $l3_link;
			$index++;
		}
		unset($final_links2['aa'][$key]);
		
		$have_l3 = false;
	}
	$final_links3['mm'] = $final_links2['mm'];
	$final_links3['am'] = $final_links2['am'];
	
	$cleaned_links = array();
	foreach ($final_links3['aa'] as $link) {
		$cleaned_links[] = $link;
	}
	foreach ($final_links3['am'] as $link) {
		$cleaned_links[] = $link;
	}
	foreach ($final_links3['mm'] as $link) {
		$cleaned_links[] = $link;
	}
	
	return $cleaned_links;
}

function is_in_rel_array ($relations, $relation) {
	$is_in_array = false;
	foreach ($relations as $rel) {
		if ($rel['id_parent_source_data'] == $relation['id_parent_source_data'] && 
			$rel['id_child_source_data'] == $relation['id_child_source_data']) {
			$is_in_array = true;
		}
	}
	return $is_in_array;
}

function show_networkmap($id = 0, $user_readonly = false, $nodes_and_relations = array()) {
	global $config;
	
	$clean_relations = clean_duplicate_links($nodes_and_relations['relations']);
	
	$nodes_and_relations['relations'] = $clean_relations;
	
	$networkmap = db_get_row('tmap', 'id', $id);
	$networkmap['filter'] = json_decode($networkmap['filter'], true);
	
	$networkmap['filter']['l2_network_interfaces'] = 1;
	
	echo '<script type="text/javascript" src="' . $config['homeurl'] . 'include/javascript/d3.3.5.14.js" charset="utf-8"></script>';
	ui_require_css_file("jquery.contextMenu", 'include/javascript/');
	echo '<script type="text/javascript" src="' . $config['homeurl'] . 'include/javascript/jquery.contextMenu.js"></script>';
	echo '<script type="text/javascript" src="' . $config['homeurl'] . 'include/javascript/functions_pandora_networkmap.js"></script>';
	echo '<div id="networkconsole" style="position: relative; overflow: hidden; background: #FAFAFA">';
		
		echo '<canvas id="minimap"
			style="position: absolute; left: 0px; top: 0px; border: 1px solid #3a4a70;">
			</canvas>';
		
		echo '<div id="arrow_minimap" style="position: absolute; left: 0px; top: 0px;">
				<a title="' . __('Open Minimap') . '" href="javascript: toggle_minimap();">
					<img id="image_arrow_minimap" src="images/minimap_open_arrow.png" />
				</a>
			</div>';
		
	echo '</div>';
	
	?>
<style type="text/css">
	.node {
		stroke: #fff;
		stroke-width: 1px;
	}
	
	.node_over {
		stroke: #999;
	}
	
	.node_selected {
		stroke:#000096;
		stroke-width:3;
	}
	
	.node_children {
		stroke: #00f;
	}
	
	.link {
		stroke: #999;
		stroke-opacity: .6;
	}
	
	.link_over {
		stroke: #000;
		stroke-opacity: .6;
	}
	
	.holding_area {
		stroke: #0f0;
		stroke-dasharray: 12,3;
	}
	
	.holding_area_link {
		stroke-dasharray: 12,3;
	}
</style>

<script type="text/javascript">
	<?php
	networkmap_write_js_array($id, $nodes_and_relations);
	?>
	////////////////////////////////////////////////////////////////////////
	// document ready
	////////////////////////////////////////////////////////////////////////
	$(document).ready(function() {
		init_graph({
			refesh_period: networkmap_refresh_time,
			graph: networkmap,
			networkmap_center: networkmap_center,
			url_popup: url_popup_pandora,
			networkmap_dimensions: networkmap_dimensions,
			enterprise_installed: enterprise_installed,
			holding_area_dimensions: networkmap_holding_area_dimensions,
			url_background_grid: url_background_grid
		});
		init_drag_and_drop();
		init_minimap();
		function_open_minimap();
		
		window.interval_obj = setInterval(update_networkmap, networkmap_refresh_time);
		
		$(document.body).on("mouseleave",
			".context-menu-list",
			function(e) {
				try {
					$("#networkconsole").contextMenu("hide");
				}
				catch(err) {
				}
			}
		);
	});
</script>
<?php
$list_networkmaps = get_networkmaps($id);
if (empty($list_networkmaps))
	$list_networkmaps = array();
?>

<div id="dialog_node_edit" style="display: none;" title="<?php echo __('Edit node');?>">
	<div style="text-align: left; width: 100%;">
	<?php
	
	$table = null;
	$table->id = 'node_options';
	$table->width = "100%";
	
	$table->data = array();
	$table->data[0][0] = __('Shape');
	$table->data[0][1] = html_print_select(array(
		'circle' => __('Circle'),
		'square' => __('Square'),
		'rhombus' => __('Rhombus')), 'shape', '',
		'javascript:', '', 0, true) . '&nbsp;' .
		'<span id="shape_icon_in_progress" style="display: none;">' . 
			html_print_image('images/spinner.gif', true) . '</span>' .
		'<span id="shape_icon_correct" style="display: none;">' .
			html_print_image('images/dot_green.png', true) . '</span>' .
		'<span id="shape_icon_fail" style="display: none;">' .
			html_print_image('images/dot_red.png', true) . '</span>';
	$table->data["fictional_node_name"][0] = __('Name');
	$table->data["fictional_node_name"][1] = html_print_input_text('edit_name_fictional_node',
		'', __('name fictional node'), '20', '50', true);
	$table->data["fictional_node_networkmap_link"][0] = __('Networkmap to link');
	$table->data["fictional_node_networkmap_link"][1] =
		html_print_select($list_networkmaps, 'edit_networkmap_to_link',
			'', '', '', 0, true);
	$table->data["fictional_node_update_button"][0] = '';
	$table->data["fictional_node_update_button"][1] =
		html_print_button(__('Update fictional node'), '', false,
			'add_fictional_node();', 'class="sub"', true);
	
	ui_toggle(html_print_table($table, true), __('Node options'),
		__('Node options'), true);
	
	$table = null;
	$table->id = 'relations_table';
	$table->width = "100%";
	
	$table->head = array();
	$table->head['node_source'] = __('Node source');
	if ($networkmap['options']['l2_network_interfaces']) {
		$table->head['interface_source'] = __('Interface source');
		$table->head['interface_target'] = __('Interface Target');
	}
	$table->head['node_target'] = __('Node target');
	$table->head['edit'] = '<span title="' . __('Edit') . '">' . __('E.') . '</span>';
	
	$table->data = array();
	$table->rowstyle['template_row'] = 'display: none;';
	$table->data['template_row']['node_source'] = '';
	if ($networkmap['options']['l2_network_interfaces']) {
		$table->data['template_row']['interface_source'] = 
			html_print_select(array(), 'interface_source', '', '',
				__('None'), 0, true);
		$table->data['template_row']['interface_target'] =
			html_print_select(array(), 'interface_target', '', '',
				__('None'), 0, true);
	}
	$table->data['template_row']['node_target'] = '';
	$table->data['template_row']['edit'] = "";
	
	$table->data['template_row']['edit'] = '';
	
	if ($networkmap['options']['l2_network_interfaces']) {
		$table->data['template_row']['edit'] .=
			'<span class="edit_icon_correct" style="display: none;">' . 
				html_print_image('images/dot_green.png', true) . '</span>' .
			'<span class="edit_icon_fail" style="display: none;">' . 
				html_print_image('images/dot_red.png', true) . '</span>' .
			'<span class="edit_icon_progress" style="display: none;">' . 
				html_print_image('images/spinner.gif', true) . '</span>' .
			'<span class="edit_icon"><a class="edit_icon_link" title="' . __('Update') . '" href="#">' .
			html_print_image('images/config.png', true) . '</a></span>';
	}
	
	$table->data['template_row']['edit'] .=
		'<a class="delete_icon" href="#">' .
		html_print_image('images/delete.png', true) . '</a>';
	
	$table->colspan['no_relations']['0'] = 5;
	$table->cellstyle['no_relations']['0'] = 'text-align: center;';
	$table->data['no_relations']['0'] = __('There are not relations');
	
	$table->colspan['loading']['0'] = 5;
	$table->cellstyle['loading']['0'] = 'text-align: center;';
	$table->data['loading']['0'] = html_print_image(
		'images/wait.gif', true);
	
	
	ui_toggle(html_print_table($table, true), __('Relations'),
		__('Relations'), false);
	?>
	</div>
</div>

<div id="dialog_node_add" style="display: none;" title="<?php echo __('Add node');?>">
	<div style="text-align: left; width: 100%;">
		<?php
		$table = null;
		$table->width = "100%";
		$table->data = array();
		
		$table->data[0][0] = __('Agent');
		$params = array();
		$params['return'] = true;
		$params['show_helptip'] = true;
		$params['input_name'] = 'agent_name';
		$params['input_id'] = 'agent_name';
		$params['print_hidden_input_idagent'] = true;
		$params['hidden_input_idagent_name'] = 'id_agent';
		$params['disabled_javascript_on_blur_function'] = true;
		$table->data[0][1] = ui_print_agent_autocomplete_input($params);
		$table->data[1][0] = '';
		$table->data[1][1] =
			html_print_button(__('Add agent node'), '', false,
				'add_agent_node();', 'class="sub"', true);
		
		$add_agent_node_html = html_print_table($table, true);
		ui_toggle($add_agent_node_html, __('Add agent node'),
			__('Add agent node'), false);
		
		$table = null;
		$table->width = "100%";
		$table->data = array();
		$table->data[0][0] = __('Group');
		$table->data[0][1] = html_print_select_groups(false, "IW",
			false,
			'group_for_show_agents',
			-1,
			'choose_group_for_show_agents()',
			__('None'),
			-1,
			true);
		$table->data[1][0] = __('Agents');
		$table->data[1][1] = html_print_select(
			array(-1 => __('None')), 'agents_filter_group', -1, '', '',
			0, true, true, true, '', false, "width: 170px;", false, 5);
		$table->data[2][0] = '';
		$table->data[2][1] =
			html_print_button(__('Add agent node'), '', false,
				'add_agent_node_from_the_filter_group();', 'class="sub"', true);
		
		$add_agent_node_html = html_print_table($table, true);
		ui_toggle($add_agent_node_html, __('Add agent node (filter by group)'),
			__('Add agent node'), true);
		
		$table = null;
		$table->width = "100%";
		$table->data = array();
		$table->data[0][0] = __('Name');
		$table->data[0][1] = html_print_input_text('name_fictional_node',
			'', __('name fictional node'), '20', '50', true);
		$table->data[1][0] = __('Networkmap to link');
		$table->data[1][1] =
			html_print_select($list_networkmaps, 'networkmap_to_link',
				'', '', '', 0, true);
		$table->data[2][0] = '';
		$table->data[2][1] =
			html_print_button(__('Add fictional node'), '', false,
				'add_fictional_node();', 'class="sub"', true);
		$add_agent_node_html = html_print_table($table, true);
		ui_toggle($add_agent_node_html, __('Add fictional point'),
			__('Add agent node'), true);
		?>
	</div>
</div>
	<?php
}

?>
