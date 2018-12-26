<?php
/**
 * Handles relevant functions for emails
 *
 * @package QSM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class contains functions for loading, saving, and send quiz emails.
 *
 * @since 6.1.0
 */
class QSM_Emails {

	/**
	 * Creates the HTML for the results page.
	 *
	 * @since 6.1.0
	 * @param array $response_data The data for the user's submission.
	 * @return string The HTML for the page to be displayed.
	 */
	public static function generate_pages( $response_data ) {
		$pages            = QSM_Results_Pages::load_pages( $response_data['quiz_id'] );
		$default          = '%QUESTIONS_ANSWERS%';
		$redirect         = false;
		$default_redirect = false;
		ob_start();
		?>
		<div class="qsm-results-page">
			<?php
			do_action( 'qsm_before_results_page' );

			// Cycles through each possible page.
			foreach ( $pages as $page ) {

				// Checks if any conditions are present. Else, set it as the default.
				if ( ! empty( $page['conditions'] ) ) {
					/**
					 * Since we have many conditions to test, we set this to true first.
					 * Then, we test each condition to see if it fails.
					 * If one condition fails, the value will be set to false.
					 * If all conditions pass, this will still be true and the page will
					 * be shown.
					 */
					$show = true;

					// Cycle through each condition to see if we should show this page.
					foreach ( $page['conditions'] as $condition ) {
						$value = $condition['value'];

						// First, determine which value we need to test.
						switch ( $condition['criteria'] ) {
							case 'score':
								$test = $response_data['total_score'];
								break;

							case 'points':
								$test = $response_data['total_points'];
								break;

							default:
								$test = 0;
								break;
						}

						// Then, determine how to test the vaue.
						switch ( $condition['operator'] ) {
							case 'greater-equal':
								if ( $test < $value ) {
									$show = false;
								}
								break;

							case 'greater':
								if ( $test <= $value ) {
									$show = false;
								}
								break;

							case 'less-equal':
								if ( $test > $value ) {
									$show = false;
								}
								break;

							case 'less':
								if ( $test >= $value ) {
									$show = false;
								}
								break;

							case 'not-equal':
								if ( $test == $value ) {
									$show = false;
								}
								break;

							case 'equal':
							default:
								if ( $test != $value ) {
									$show = false;
								}
								break;
						}

						/**
						 * Added custom criterias/operators to the results pages?
						 * Use this filter to check if the condition passed.
						 * If it fails your conditions, return false to prevent the
						 * page from showing.
						 * If it passes your condition or is not your custom criterias
						 * or operators, then return the value as-is.
						 * DO NOT RETURN TRUE IF IT PASSES THE CONDITION!!!
						 * The value may have been set to false when failing a previous condition.
						 */
						$show = apply_filters( 'qsm_results_page_condition_check', $show, $condition, $response_data );
					}

					// If we passed all conditions, show this page.
					if ( $show ) {
						$content = $page['page'];
						if ( $page['redirect'] ) {
							$redirect = $page['redirect'];
						}
					}
				} else {
					$default = $page['page'];
					if ( $page['redirect'] ) {
						$default_redirect = $page['redirect'];
					}
				}
			}

			// If no page was set to the content, set to the page that was a default page.
			if ( empty( $content ) ) {
				$content = $default;
			}

			// If no redirect was set, set to default redirect.
			if ( ! $redirect ) {
				$redirect = $default_redirect;
			}

			// Decodes special characters, runs through our template
			// variables, and then outputs the text.
			$page = htmlspecialchars_decode( $content, ENT_QUOTES );
			$page = apply_filters( 'mlw_qmn_template_variable_results_page', $page, $response_data );
			echo str_replace( "\n", '<br>', $page );
			do_action( 'qsm_after_results_page' );
			?>
		</div>
		<?php
		return array(
			'display'  => do_shortcode( ob_get_clean() ),
			'redirect' => $redirect,
		);
	}

	/**
	 * Loads the emails for a single quiz.
	 *
	 * @since 6.1.0
	 * @param int $quiz_id The ID for the quiz.
	 * @return bool|array The array of emails or false.
	 */
	public static function load_pages( $quiz_id ) {
		$pages   = array();
		$quiz_id = intval( $quiz_id );

		// If the parameter supplied turns to 0 after intval, returns false.
		if ( 0 === $quiz_id ) {
			return false;
		}

		global $wpdb;
		$data    = $wpdb->get_row( $wpdb->prepare( "SELECT system, message_after FROM {$wpdb->prefix}mlw_quizzes WHERE quiz_id = %d", $quiz_id ), ARRAY_A );
		$results = $data['message_after'];
		$system  = $data['system'];

		// Checks if the results is an array.
		if ( is_serialized( $results ) && is_array( maybe_unserialize( $results ) ) ) {
			$results = maybe_unserialize( $results );

			// Checks if the results array is not the newer version.
			if ( ! isset( $results[0]['conditions'] ) ) {
				foreach ( $results as $page ) {
					$new_page = array(
						'conditions' => array(),
						'page'       => $page[2],
						'redirect'   => false,
					);

					if ( ! empty( $page['redirect_url'] ) ) {
						$new_page['redirect'] = $page['redirect_url'];
					}

					// Checks to see if the page is not the older version's default page.
					if ( 0 !== intval( $page[0] ) || 0 !== intval( $page[1] ) ) {

						// Checks if the system is points.
						if ( 1 === intval( $system ) ) {
							$new_page['conditions'][] = array(
								'criteria' => 'points',
								'operator' => 'greater-equal',
								'value'    => $page[0],
							);
							$new_page['conditions'][] = array(
								'criteria' => 'points',
								'operator' => 'less-equal',
								'value'    => $page[1],
							);
						} else {
							$new_page['conditions'][] = array(
								'criteria' => 'score',
								'operator' => 'greater-equal',
								'value'    => $page[0],
							);
							$new_page['conditions'][] = array(
								'criteria' => 'score',
								'operator' => 'less-equal',
								'value'    => $page[1],
							);
						}
					}

					$pages[] = $new_page;
				}

				// Updates the database with new array to prevent running this step next time.
				$wpdb->update(
					$wpdb->prefix . 'mlw_quizzes',
					array( 'message_after' => serialize( $pages ) ),
					array( 'quiz_id' => $quiz_id ),
					array( '%s' ),
					array( '%d' )
				);
			} else {
				$pages = $results;
			}
		} else {
			$pages = array(
				array(
					'conditions' => array(),
					'page'       => $results,
					'redirect'   => false,
				),
			);
		}

		return $emails;
	}

	/**
	 * Saves the emails for a quiz.
	 *
	 * @since 6.1.0
	 * @param int   $quiz_id The ID for the quiz.
	 * @param array $emails The emails to be saved.
	 * @return bool True or false depending on success.
	 */
	public static function save_emails( $quiz_id, $emails ) {
		if ( ! is_array( $emails ) ) {
			return false;
		}

		$quiz_id = intval( $quiz_id );
		if ( 0 === $quiz_id ) {
			return false;
		}

		global $wpdb;

		$results = $wpdb->update(
			$wpdb->prefix . 'mlw_quizzes',
			array( 'message_after' => serialize( $emails ) ),
			array( 'quiz_id' => $quiz_id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false !== $results ) {
			return true;
		} else {
			return false;
		}
	}
}
?>