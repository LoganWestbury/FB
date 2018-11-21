<?php
/**
 * Plugin Name: Football Pool Ranking Score Breakdown
 * Description: Show score breakdown in the ranking table (full points, toto points, goal bonus, goal difference, question points).
 * Version: 1.2
 * Author: Antoine Hurkmans
 * Author URI: mailto:wordpressfootballpool@gmail.com
 * License: MIT
 */

// Save this plugin in the "/wp-content/plugins" folder and activate it //

class FootballPoolExtensionRankingScoreBreakdown {
	public static function init_extension() {
		// display a message if the Football Pool plugin is not activated.
		if ( ! class_exists( 'Football_Pool' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'disable_extension' ) );
			return;
		}
		
		// add a row with column headers and the opening <tbody> to the template
		add_filter( 'footballpool_ranking_template_start', array( __CLASS__, 'template_start' ) , null, 6 );
		// add </tbody> before </table>
		add_filter( 'footballpool_ranking_template_end', array( __CLASS__, 'template_end' ) , null, 6 );
		// add the new columns to the row template
		add_filter( 'footballpool_ranking_ranking_row_template', array( __CLASS__, 'ranking_row_template' ), null, 3 );
		// add the score breakdown data to the data set
		add_filter( 'footballpool_ranking_ranking_row_params', array( __CLASS__, 'change_params' ), null, 7 );
	}
	
	public static function disable_extension() {
		echo '<div class="error"><p>The Football Pool plugin is not activated. Make sure you activate it so the Football Pool extension plugin has some use.</p></div>';
	}
	
	public static function template_end( $template_end, $league, $user, $ranking_id, $all_user_view, $type ) {
		return '</tbody></table>';
	}
	
	public static function template_start( $template_start, $league, $user, $ranking_id, $all_user_view, $type ) {
		$template_start .= sprintf( '<thead>
										<tr>
										<th></th>
										<th class="user">%s</th>
										<th class="num-predictions">%s</th>
										<th class="score-breakdown full">%s</th>
										<th class="score-breakdown toto">%s</th>
										<th class="score-breakdown goalbonus">%s</th>
										<th class="score-breakdown goaldiff">%s</th>
										<th class="score">%s</th>
										%s</tr>
									</thead>
									<tbody>'
									, __( 'user', 'football-pool' )
									, __( 'predictions', 'football-pool' )
									, __( 'full', 'football-pool' )
									, __( 'toto', 'football-pool' )
									, __( 'goal bonus', 'football-pool' )
									, __( 'goal diff', 'football-pool' )
									, __( 'points', 'football-pool' )
									, ( $all_user_view ? '<th></th>' : '' )
							);
		return $template_start;
	}
	
	public static function ranking_row_template( $template, $all_user_view, $type ) {
		if ( $all_user_view ) {
			$ranking_template = '<tr class="%css_class%">
									<td style="width:3em; text-align: right;">%rank%.</td>
									<td><a href="%user_link%">%user_avatar%%user_name%</a></td>
									<td class="num-predictions">%num_predictions%</td>
									<td class="score-breakdown full">%breakdown_full_points%</td>
									<td class="score-breakdown toto">%breakdown_toto_points%</td>
									<td class="score-breakdown goalbonus">%breakdown_goalbonus_points%</td>
									<td class="score-breakdown goaldiff">%breakdown_goaldiff_points%</td>
									<td class="ranking score">%points%</td>
									<td>%league_image%</td>
									</tr>';
		} else {
			$ranking_template = '<tr class="%css_class%">
									<td style="width:3em; text-align: right;">%rank%.</td>
									<td><a href="%user_link%">%user_avatar%%user_name%</a></td>
									<td class="num-predictions">%num_predictions%</td>
									<td class="score-breakdown full">%breakdown_full_points%</td>
									<td class="score-breakdown toto">%breakdown_toto_points%</td>
									<td class="score-breakdown goalbonus">%breakdown_goalbonus_points%</td>
									<td class="score-breakdown goaldiff">%breakdown_goaldiff_points%</td>
									<td class="ranking score">%points%</td>
									</tr>';
		}
		
		return $ranking_template;
	}
	
	private static function get_breakdown( $ranking_id ) {
		$cache_key = 'fpx_score_breakdown';
		$breakdown = wp_cache_get( $cache_key );
		
		if ( $breakdown === false ) {
			global $wpdb;
			$prefix = FOOTBALLPOOL_DB_PREFIX;
			$match = FOOTBALLPOOL_TYPE_MATCH;
			$question = FOOTBALLPOOL_TYPE_QUESTION;
			
			$pool = new Football_Pool_Pool();
			$scorehistory = $pool->get_score_table();
						
			$breakdown = array();
			
			// Breakdown for the matches
			$sql = "SELECT 
						`user_id`
						,SUM( `full` ) AS `breakdown_full`
						,SUM( `toto` ) AS `breakdown_toto`
						,SUM( `goal_bonus` ) AS `breakdown_goalbonus`
						,SUM( `goal_diff_bonus` ) AS `breakdown_goaldiff`
					FROM `{$prefix}{$scorehistory}` 
					WHERE `ranking_id` = {$ranking_id} AND `type` = {$match}
					GROUP BY `user_id` 
					ORDER BY `user_id` ASC";
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			
			foreach ( $rows as $row ) {
				$breakdown[(int) $row['user_id']] = array( 
													'full' => (int) $row['breakdown_full'],
													'toto' => (int) $row['breakdown_toto'],
													'goalbonus' => (int) $row['breakdown_goalbonus'],
													'goaldiff' => (int) $row['breakdown_goaldiff'],
												);
			}
			
			// Add bonusquestion points
			$sql = "SELECT 
						`user_id` 
						,COUNT( IF( `score` > 0, 1, NULL ) ) AS `breakdown_question`
						,SUM( `score` ) AS `breakdown_question_points`
					FROM `{$prefix}{$scorehistory}`
					WHERE `ranking_id` = {$ranking_id} AND `type` = {$question}
					GROUP BY `user_id` 
					ORDER BY `user_id` ASC";
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			
			foreach( $rows as $row ) {
				$breakdown[(int) $row['user_id']]['question_correct'] = $row['breakdown_question']; 
				$breakdown[(int) $row['user_id']]['question_points'] = $row['breakdown_question_points']; 
			}
			
			wp_cache_set( $cache_key, $breakdown );
		}
		
		return $breakdown;
	}
	
	public static function change_params( $params, $league, $user, $ranking_id, $all_user_view, $type, $row ) {
		$user_id = (int) $params['user_id'];
		$breakdown = self::get_breakdown( $ranking_id );
		
		// set the params to 0
		$params['breakdown_full'] = $params['breakdown_full_points'] = 0;
		$params['breakdown_toto'] = $params['breakdown_toto_points'] = 0;
		$params['breakdown_goalbonus'] = $params['breakdown_goalbonus_points'] = 0;
		$params['breakdown_goaldiff'] = $params['breakdown_goaldiff_points'] = 0;
		$params['breakdown_question'] = 0;
		$params['breakdown_question_points'] = 0;
		
		if ( array_key_exists( $user_id, $breakdown ) ) {
			$full = (int) Football_Pool_Utils::get_fp_option( 'fullpoints', FOOTBALLPOOL_FULLPOINTS, 'int' );
			$toto = (int) Football_Pool_Utils::get_fp_option( 'totopoints', FOOTBALLPOOL_TOTOPOINTS, 'int' );
			$goal = (int) Football_Pool_Utils::get_fp_option( 'goalpoints', FOOTBALLPOOL_GOALPOINTS, 'int' );
			$diff = (int) Football_Pool_Utils::get_fp_option( 'diffpoints', FOOTBALLPOOL_DIFFPOINTS, 'int' );
			
			// check for matches
			if ( array_key_exists( 'full', $breakdown[$user_id] ) ) {
				$params['breakdown_full'] = $breakdown[$user_id]['full'];
				$params['breakdown_full_points'] = $full * $breakdown[$user_id]['full'];
				
				$params['breakdown_toto'] = $breakdown[$user_id]['toto'];
				$params['breakdown_toto_points'] = $toto * $breakdown[$user_id]['toto'];
				
				$params['breakdown_goalbonus'] = $breakdown[$user_id]['goalbonus'];
				$params['breakdown_goalbonus_points'] = $goal * $breakdown[$user_id]['goalbonus'];
				
				$params['breakdown_goaldiff'] = $breakdown[$user_id]['goaldiff'];
				$params['breakdown_goaldiff_points'] = $diff * $breakdown[$user_id]['goaldiff'];
			}
			
			// check for questions
			if ( array_key_exists( 'question_correct', $breakdown[$user_id] ) ) {
				$params['breakdown_question'] = $breakdown[$user_id]['question_correct'];
				$params['breakdown_question_points'] = $breakdown[$user_id]['question_points'];
			}
		}
		
		return $params;
	}
}

add_filter( 'plugins_loaded', array( 'FootballPoolExtensionRankingScoreBreakdown', 'init_extension' ) );
