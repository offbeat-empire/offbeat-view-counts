<?php
/**
 * Plugin Name: Offbeat View Counts
 * Description: Jetpack enabled view counter for Offbeat Empire
 * Version: 1
 * Author: Kelly Maguire
 */

class OffbeatViewCounter {

	public function __construct(){
		add_action('init', array($this, 'setup_schedule'));
		add_filter( 'cron_schedules', array($this, 'cron_add_every_20_minutes'));
		add_action('obe_update', array($this, 'update'), 10, 1);
	}

	public function cron_add_every_20_minutes( $schedules ){
		$schedules['fortminutely'] = array(
			'interval' => 1200,
			'display' => __( 'Every 20 Minutes')
		);
		return $schedules;
	}

	public function setup_schedule(){
		if(! wp_next_scheduled( 'obe_update',   array('temperature' => 'warm')) )
			wp_schedule_event( time(), 'hourly', 'obe_update', array('temperature' => 'warm'));
		if(! wp_next_scheduled( 'obe_update',   array('temperature' => 'cold')) )
			wp_schedule_event( time(), 'daily', 'obe_update', array('temperature' => 'cold'));
		if(! wp_next_scheduled( 'obe_update',   array('temperature' => 'hot')) )
			wp_schedule_event( time(), 'fortminutely', 'obe_update', array('temperature' => 'hot'));
		
	}

	public function update($temperature = 'hot'){
		switch($temperature){
			case 'warm':
				$post_ids =  implode(',', $this->get_warm_posts()->posts);
				break;
			case 'cold':
				$post_ids =  implode(',', $this->get_cold_posts()->posts);
				break;
			default:
				$post_ids =  implode(',', $this->get_hot_posts()->posts);
		}

		$csvargs = array(
			'post_id' => $post_ids,
			'limit' => 500,
			'days' => -1);
		$response = stats_get_csv('postviews', $csvargs);
		$result = $this->update_post_counts($response);
	}

	function get_post_ids($number, $offset = 0){
		$args = array(
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'no_found_rows' => true,
			'fields' => 'ids',
			'offset' => $offset,
			'posts_per_page' => $number);
		$query = new WP_Query($args);

		return $query;
	}

	//Ten most recent posts need the freshest updating
	function get_hot_posts(){
		return $this->get_post_ids(10);
	}

	//Next 5 pages need medium freshness
	function get_warm_posts(){
		return $this->get_post_ids(40, 10);
	}

	//Everything else can get updated sporadically
	function get_cold_posts(){
		$last_post_id = get_option('ovc_last_updated_post_id');
		if($last_post_id) {
			$last_post = new WP_Query(
				array(
					'p' => $last_post_id,
					'no_found_rows' => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false
					));
			$last_post_date = $last_post->posts[0]->post_date;

		}
		$args = array(
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'no_found_rows' => true,
			'fields' => 'ids',
			'offset' => 500,
			'posts_per_page' => 500);

		//if we have a last post date, get posts published earlier than that
		if($last_post_date)
			$args['date_query'] = array( 'before' => $last_post_date);
		$query = new WP_Query($args);

		//reset if we're at the end
		if($query->post_count < 500) {
			$this->unset_last_post();
		} else {
			//update last post with the most recent one
			$last_post = end(array_values($query->posts));
			update_option('ovc_last_updated_post_id', $last_post);
			//set a cron for 5 minutes to loop through more
			wp_schedule_single_event( time() + 300 , 'obe_update', array('temperature' => 'cold'));

		}
		
		return $query;
	}

	//Write the fetched post counts to the database
	//returns an array with the number of successful updates, number of skipped or faulty updates, and ID of last post updated
	function update_post_counts($response){
    	$count_key = 'post_views_count';
    	$success = 0;
    	$failure = 0;
    	foreach ($response as $item){
	    	$post_id = $item["post_id"];
	    	if(update_post_meta($post_id, $count_key, $item["views"])) {
	    		$success++;
	    	} else {
	    		$failure++;
	    	}
		}

		return array('success' => $success, 'failure' => $failure);
	}

	function unset_last_post() {
		delete_option('ovc_last_updated_post_id');
	}

}

$ovc = new OffbeatViewCounter();