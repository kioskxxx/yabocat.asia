%5+1
%5+1
<?php

/**
 * Class ActionScheduler_Schedule
 */
interface ActionScheduler_Schedule {
	/**
	 * @param DateTime $after
	 * @return DateTime|null
	 */
	public function next( DateTime $after = NULL );

	/**
	 * @return bool
	 */
	public function is_recurring();
}
 