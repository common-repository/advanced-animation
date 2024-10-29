<?php
/**
 * Plugin Name:       Advanced Addons
 * Description:       Animation and figma converter for gutenberg block.
 * Requires at least: 5.0
 * Requires PHP:      5.6
 * Version:           2.2.3
 * Author:            Advanced Addons
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       advanced-animation
 * Domain Path: /languages
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}


define( "ADVANCED_ADDONS_VERSION", "2.2.3" );

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/block-editor/how-to-guides/block-tutorial/writing-your-first-block-type/
 */
function create_block_advanced_animation_block_init() {
	register_block_type( __DIR__ );
}
add_action( 'init', 'create_block_advanced_animation_block_init' );


function advanced_animation_load_textdomain() {
	load_plugin_textdomain(
		'advanced-animation',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);
	wp_set_script_translations( 'advanced-animation-script', 'advanced-animation' );
}
add_action( 'init', 'advanced_animation_load_textdomain' ); 

// register custom meta tag field
function advanced_animation_post_meta() {
	$types = array("post", "page", "wp_block");
	foreach($types as $type){

		register_post_meta( $type, '_advanced_animation_props', array(
				'show_in_rest' => true,
				'single' => true,
				'type' => 'string',
				'auth_callback' => function() {
					return current_user_can( 'edit_posts' );
				}
		) );
		register_post_meta( $type, '_moveable', array(
			'show_in_rest' => true,
			'single' => true,
			'type' => 'string',
			'auth_callback' => function() {
				return current_user_can( 'edit_posts' );
			}
	  ) );
	  register_post_meta( $type, '_advanced_block_media_mapping', array(
		  	'single' => true,
		  	'type' => 'object',
		  	'show_in_rest' => array(
				'schema' => array(
					'type'       => 'object',
					'additionalProperties' => array(
						'type' => 'string',
					),
				),
         	),
			'auth_callback' => function() {
				return current_user_can( 'edit_posts' );
			}
	  ) );
	}

}
add_action( 'init', 'advanced_animation_post_meta' );

function advanced_animation_script(){
	global $post;
	$post_id = $post->ID;
	$animation = get_post_meta($post_id, "_advanced_animation_props", true);
	$moveable = get_post_meta($post_id, "_moveable", true);
	$fe_script = require "build/frontend.asset.php";

	wp_enqueue_script('frontend-script', plugin_dir_url(__FILE__) . 'build/frontend.js',  $fe_script["dependencies"], $fe_script["version"], true);
	wp_localize_script('frontend-script', 'animationGlobal', array("props" => $animation));
	$inline_css = "";
	if(!empty($moveable)){
		$decode = json_decode($moveable);
		foreach($decode as $id=>$properties){
			if($id && $properties && $id != "undefined" && !empty($properties) && is_object($properties)){
				$inline_css .= ".position-$id{";
				foreach($properties as $key => $value){
					if(!empty($value) && (is_string($value) || is_numeric($value)) && $key !== "css"){
						$important = "";
						if(strpos($key, 'margin') !== false || strpos($key, 'padding') !== false){
							$important = "!important";
						}
						$inline_css .= "$key:$value$important; ";
					}
				}
				$inline_css .= "}";			
				$inline_css .= 	!empty($properties->css) ? str_replace("element", ".position-$id", $properties->css) : "";	
			}
		}
	}
	wp_enqueue_style('frontend-style', plugin_dir_url(__FILE__) . 'build/style-index.css',  array(), ADVANCED_ADDONS_VERSION, "all");
	wp_add_inline_style( 'frontend-style', $inline_css );
}

add_action( 'wp_enqueue_scripts', 'advanced_animation_script', 999 );

function advanced_animation_admin_script(){	
	$data = get_option("_advanced_animation_license_data");
	$figma = get_option("_advanced_animation_figma_data");
	
	wp_localize_script('advanced-block-box-editor-script', 'animationGlobal', array(
		"data" => $data,
		"figmaData" => $figma,
		"baseUrl" => home_url(),			
		'nonce' => wp_create_nonce('wp_rest'),
	));
}
add_action( 'admin_enqueue_scripts', 'advanced_animation_admin_script' );

function advanced_animation_render_block($block_content, $block){
	global $post;
	$post_id = $post->ID;
	$animation = get_post_meta($post_id, "_advanced_animation_props", true);
	$animation_meta = json_decode($animation);	
	if(!isset($block['attrs'])){
		return $block_content;
	}

	$attrs = $block['attrs'];

	$class = "";
	$style = "";
	if(isset($attrs["classAnimation"]) && count($attrs["classAnimation"]) && !empty($attrs["animationId"])){
		$class = empty($class) ? "animation-". $attrs["animationId"] : $class ." animation-". $attrs["animationId"];
		if(isset($animation_meta->settings) && isset($animation_meta->settings->{$attrs["animationId"]})){
			$settings = $animation_meta->settings->{$attrs["animationId"]};
			$first_flag = false;
			foreach($settings as $key => $value){
				if(!$first_flag && isset($value->triggerType) && isset($animation_meta->animations->{$key})){
					$init_styles = $animation_meta->animations->{$key}->keyframes->{"0%"};
					if(!empty($init_styles)){

						$style="<style> .animation-".$attrs['animationId']."{";
						foreach($init_styles as $css_property => $css_value){
							if($css_property === "transform"){
								$style .= "transform:";
								foreach($init_styles->transform as $transform_property => $transform_value){
									$style .= "$transform_property($transform_value)";
								}
								$style .= ";";
							}else{
								$style .= "$css_property:$css_value;";
							}
						}

						$style.="}</style>";
						$first_flag = true;
					}
				}

			}
		}

		$data = 'data-scroll data-scroll-id="'. $attrs["animationId"] .'" class';
		
		$pos = strpos($block_content, "class");
		if ($pos !== false) {
			$block_output = substr_replace($block_content, $data, $pos, 5);
			return $style .$block_output;
		}

	}

	if(isset($attrs["parallax"]) && !empty($attrs["parallax"]["enable"])){
		$class = empty($class) ? "block-rellax" : $class ." block-rellax";		

		$data = "";
		if(isset($attrs["parallax"])){
			if(isset($attrs["parallax"]["speed"]) && $attrs["parallax"]["speed"] !== -2){
				$speed = $attrs["parallax"]["speed"];
				$data .= "data-rellax-speed='$speed' "; 
			}

			if(isset($attrs["parallax"]["zindex"]) && $attrs["parallax"]["zindex"] !== -2){
				$zindex = $attrs["parallax"]["zindex"];
				$data .= "data-rellax-zindex='$zindex' "; 
			}

			if($data !== "" && is_string($block_content)){
				$data .= "class";
				$pos = strpos($block_content, "class");
				if ($pos !== false) {
					$block_output = substr_replace($block_content, $data, $pos, 5);
					return $block_output;
				}
			}
		}
	}

	if(isset($attrs["moveable"]) && (!empty($attrs["moveable"]["transform"]) || !empty($attrs["moveable"]["style"]) || !empty($attrs["moveable"]["css"])) && isset($attrs["moveable"]["id"]) ){
		$class = empty($class) ? "position-". $attrs["moveable"]["id"] : $class ." position-". $attrs["moveable"]["id"];
	}

	if(isset($attrs["galleryAnimation"]) && isset($attrs["galleryAnimationEffect"])){
		$gallery_class = "gallery-animation gallery-animation-" .$attrs["galleryAnimationEffect"];
		$class = empty($class) ? $gallery_class : $class ." " .$gallery_class;	
	}

	if(!empty($class)){
		$pos = strpos($block_content, $class);
		$class = 'class="'. $class . " ";
		if($pos === false){
			$pos = strpos($block_content, "class");
			if ($pos !== false) {
				$block_content = substr_replace($block_content, $class, $pos, 7);
			}
		}
	}
	
	return $style .$block_content;
}

add_filter( 'render_block', 'advanced_animation_render_block', 10, 2);

function advanced_animation_rest_api(){
	register_rest_route('advanced-animation/v1', '/figma', array(
		'methods' => 'POST',
		'callback' => "advanced_animation_save_figma_data",
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	));
}
add_action('rest_api_init', 'advanced_animation_rest_api');

function advanced_animation_save_figma_data($request){
	$data = $request->get_json_params();
	update_option('_advanced_animation_figma_data', $data["data"]);
	return rest_ensure_response(array('status' => true, 'data' => $data));
}
