<?php
/**
 * WPT_Test_Event_Order class.
 *
 * @extends WP_UnitTestCase
 * @group	event-order
 */
class WPT_Test_Event_Order extends WPT_UnitTestCase {

	function setUp() {
		parent::setUp();
	}


	function test_are_events_ordered() {

		global $wp_theatre;

		$this->setup_test_data();

		$events = $wp_theatre->productions->get();

		$actual = wp_list_pluck( $events, 'ID' );

		$expected = array(
			$this->production_with_historic_event_sticky, // - 1 year, sticky
			$this->production_with_historic_event, // - 1 day
			$this->production_with_upcoming_events, // + 1 day
			$this->production_with_upcoming_event, // + 2 days
			$this->production_with_upcoming_and_historic_events, // 1 week, sticky
		);

		$this->assertEquals( $expected, $actual );

	}

	function test_is_new_event_ordered() {

		global $wp_theatre;

		$this->setup_test_data();

		// create production with upcoming event
		$this->event_in_3_days = $this->factory->post->create(
			array(
				'post_type' => WPT_Production::post_type_name,
			)
		);

		$this->event_date_in_3_days = $this->factory->post->create(
			array(
				'post_type' => WPT_Event::post_type_name,
			)
		);

		add_post_meta( $this->event_date_in_3_days, WPT_Production::post_type_name, $this->event_in_3_days );
		add_post_meta( $this->event_date_in_3_days, 'event_date', date( 'Y-m-d H:i:s', time() + (3 * DAY_IN_SECONDS) ) );

		$events = $wp_theatre->productions->get();

		$actual = wp_list_pluck( $events, 'ID' );

		$expected = array(
			$this->production_with_historic_event_sticky, // - 1 year, sticky
			$this->production_with_historic_event, // - 1 day
			$this->production_with_upcoming_events, // + 1 day
			$this->production_with_upcoming_event, // + 2 days
			$this->event_in_3_days, // + 3 days
			$this->production_with_upcoming_and_historic_events, // 1 week
		);

		$this->assertEquals( $expected, $actual );

	}

	function test_is_updated_event_ordered() {

		global $wp_theatre;

		$this->setup_test_data();

		update_post_meta( $this->upcoming_event_with_prices, 'event_date', date( 'Y-m-d H:i:s', time() + (30 * DAY_IN_SECONDS) ) );

		$events = $wp_theatre->productions->get();

		$actual = wp_list_pluck( $events, 'ID' );

		$expected = array(
			$this->production_with_historic_event_sticky, // - 1 year, sticky
			$this->production_with_historic_event, // - 1 day
			$this->production_with_upcoming_events, // + 1 day
			$this->production_with_upcoming_and_historic_events, // 1 week
			$this->production_with_upcoming_event, // + 1 month
		);

		$this->assertEquals( $expected, $actual );

	}


	/**
	 * Tests if a production is still ordered properly after the post_status has changed.
	 * Confirms #198
	 */
	function test_is_event_with_changed_status_ordered() {
		global $wp_theatre;

		$this->setup_test_data();

		// Change status of event to 'draft'.
		$draft_event_post = array(
			'ID' => $this->production_with_upcoming_events,
			'post_status' => 'draft',
		);
		wp_update_post( $draft_event_post );

		$events = $wp_theatre->productions->get( array( 'status' => 'any' ) );

		$actual = wp_list_pluck( $events, 'ID' );

		$expected = array(
			$this->production_with_historic_event_sticky, // - 1 year, sticky
			$this->production_with_historic_event, // - 1 day
			$this->production_with_upcoming_events, // + 1 day
			$this->production_with_upcoming_event, // + 2 days
			$this->production_with_upcoming_and_historic_events, // 1 week, sticky
		);

		$this->assertEquals( $expected, $actual );
	}

	function test_is_events_order_repaired_after_cron() {

		global $wp_theatre;

		$this->setup_test_data();

		// Mess up the order index.
		update_post_meta( $this->production_with_historic_event_sticky, '_wpt_order', -1 );
		update_post_meta( $this->production_with_historic_event, '_wpt_order', -1 );
		update_post_meta( $this->production_with_upcoming_events, '_wpt_order', -1 );
		update_post_meta( $this->production_with_upcoming_event, '_wpt_order', -1 );
		update_post_meta( $this->production_with_upcoming_and_historic_events, '_wpt_order', -1 );

		// Trigger update of all order indexes by triggering the wpt_cron action hook.
		do_action( 'wpt_cron' );

		$events = $wp_theatre->productions->get();

		$actual = wp_list_pluck( $events, 'ID' );

		$expected = array(
			$this->production_with_historic_event_sticky, // - 1 year, sticky
			$this->production_with_historic_event, // - 1 day
			$this->production_with_upcoming_events, // + 1 day
			$this->production_with_upcoming_event, // + 2 days
			$this->production_with_upcoming_and_historic_events, // 1 week, sticky
		);

		$this->assertEquals( $expected, $actual );

	}

	/**
	 * Tests if the results of a posts query targeting events/productions and other post types is correct.
	 * Confirms issue #224
	 * (posts query contains only events/productions when targeting events/productions and other post types)
	 */
	function test_is_mixed_posts_query_result_correct() {

		$production_args = array(
			'post_type' => WPT_Production::post_type_name,
		);

		$page_args = array(
			'post_type' => 'page',
		);

		$production_id = $this->factory->post->create( $production_args );
		$page_id = $this->factory->post->create( $page_args );

		$query_args = array(
			'post_type' => array( WPT_Production::post_type_name, 'page' ),
			'post__in' => array( $production_id, $page_id ),
		);

		$posts = get_posts( $query_args );

		$actual = count( $posts );
		$expected = 2;

		$this->assertEquals( $expected, $actual );
	}
	
	/**
	 * Test if an event has the correct order index after a date is deleted.
	 * 
	 * @access public
	 * @return void
	 */
	function test_is_event_with_deleted_date_reordered() {

		$this->setup_test_data();

		$production = new WPT_Production( $this->production_with_upcoming_events );
		$events = $production->events();

		// Make sure order index is based on first upcoming date.
		$expected = date('Y-m-d H:i:s', get_post_meta( $events[0]->ID, '_wpt_order', true ) );
		$actual = date('Y-m-d H:i:s', get_post_meta( $this->production_with_upcoming_events, '_wpt_order', true ) );		
		$this->assertEquals( $expected, $actual );

		// Delete first upcoming date.		
		wp_delete_post( $events[0]->ID, true );

		// Order index should be based on remaining date.
		$expected = date('Y-m-d H:i:s', get_post_meta( $events[1]->ID, '_wpt_order', true ) );
		$actual = date('Y-m-d H:i:s', get_post_meta( $this->production_with_upcoming_events, '_wpt_order', true ) );		
		$this->assertEquals( $expected, $actual );
	}
	
	/**
	 * Tests if an event that expires in between two order index updates has the correct order index after the second update.
	 *
	 * As of 0.15.?? Theater_Event_Order::update_order_indexes() only updates events with dates that start
	 * _after_ the last time Theater_Event_Order::update_order_indexes() ran successfully.
	 * 
	 * @since	0.15.??
	 */
	function test_is_expired_event_since_last_order_index_update_updated() {

		// Last event order completed 5 minutes ago.
		update_option( 'theater_last_succesful_update_order_indexes_timestamp', time() - MINUTE_IN_SECONDS * 5 );

		// Create event that expired < 5 minutes ago.
		$production_id = $this->factory->post->create( array( 'post_type' => WPT_Production::post_type_name ) );

		$expired_event_id = $this->factory->post->create( array( 'post_type' => WPT_Event::post_type_name ) );
		add_post_meta( $expired_event_id, WPT_Production::post_type_name, $production_id );
		add_post_meta( $expired_event_id, 'event_date', date( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS * 3 ) );
		$expired_event = new WPT_Event( $expired_event_id );
		
		$upcoming_event_id = $this->factory->post->create( array( 'post_type' => WPT_Event::post_type_name ) );
		add_post_meta( $upcoming_event_id, WPT_Production::post_type_name, $production_id );
		add_post_meta( $upcoming_event_id, 'event_date', date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$upcoming_event = new WPT_Event( $upcoming_event_id );
		
		// Make sure the event order is based on the upcoming date.
		$expected = date('Y-m-d H:i:s', get_post_meta( $upcoming_event_id, '_wpt_order', true ) );
		$actual = date('Y-m-d H:i:s', get_post_meta( $production_id, '_wpt_order', true ) );		
		$this->assertEquals( $expected, $actual );

		// Set the event order of event to order of expired date.
		update_post_meta( $production_id, '_wpt_order', get_post_meta( $expired_event_id, '_wpt_order', true ) );	
		
		// Trigger Theater_Event_Order::update_order_indexes().
		do_action( 'wpt_cron' );
		
		$expected = date('Y-m-d H:i:s', $upcoming_event->datetime() );
		$actual = date('Y-m-d H:i:s', get_post_meta( $production_id, '_wpt_order', true ) );		
		$this->assertEquals( $expected, $actual );
	}
	
	/**
	 * Tests if event are ordered by order index numerically instead of alphabetically.
	 * Alphabetical ordering causes issues with event that start before 09/09/2001 @ 1:46am (UTC) (1000000000).
	 * Confirms issue #265.
	 */
	function test_events_are_ordered_numerically() {
		global $wp_theatre;
		
		$this->setup_test_data();

		// Create an event that starts before 09/09/2001 @ 1:46am (UTC).
		$production_id = $this->factory->post->create( array( 'post_type' => WPT_Production::post_type_name ) );
		$expired_event_id = $this->factory->post->create( array( 'post_type' => WPT_Event::post_type_name ) );
		add_post_meta( $expired_event_id, WPT_Production::post_type_name, $production_id );
		add_post_meta( $expired_event_id, 'event_date', date( 'Y-m-d H:i:s', strtotime( '01-01-2001' ) ) );
		$expired_event = new WPT_Event( $expired_event_id );

		$events = $wp_theatre->productions->get();

		$actual = wp_list_pluck( $events, 'ID' );

		$expected = array(
			$production_id,
			$this->production_with_historic_event_sticky, // - 1 year, sticky
			$this->production_with_historic_event, // - 1 day
			$this->production_with_upcoming_events, // + 1 day
			$this->production_with_upcoming_event, // + 2 days
			$this->production_with_upcoming_and_historic_events, // 1 week, sticky
		);

		$this->assertEquals( $expected, $actual );

	}

}
