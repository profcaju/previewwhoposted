<?php
/**
 *
 * Preview Who Posted
 *
 * @copyright (c) 2026 profcaju
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace profcaju\previewwhoposted;

class ext extends \phpbb\extension\base
{
	/**
	 * Check if vse/topicpreview is enabled before allowing this extension to be enabled
	 *
	 * @return bool
	 */
	public function is_enableable()
	{
		$ext_manager = $this->container->get('ext.manager');

		return $ext_manager->is_enabled('vse/topicpreview');
	}
}
