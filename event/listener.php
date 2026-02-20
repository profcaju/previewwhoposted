<?php
/**
 *
 * Preview Who Posted
 *
 * @copyright (c) 2026 profcaju
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace profcaju\previewwhoposted\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use phpbb\db\driver\driver_interface as db;
use phpbb\user;

class listener implements EventSubscriberInterface
{
	/** @var db */
	protected $db;

	/** @var user */
	protected $user;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var array Indexed by topic_id => array of poster data */
	protected $whoposted_data = [];

	public function __construct(db $db, user $user, $root_path, $php_ext)
	{
		$this->db = $db;
		$this->user = $user;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.viewforum_modify_topics_data'            => 'batch_whoposted',
			'core.search_modify_rowset'                    => 'batch_whoposted_search',
			'paybas.recenttopics.modify_topics_list'       => 'batch_whoposted_recenttopics',
			'vse.similartopics.modify_rowset'              => 'batch_whoposted_similartopics',
			'vse.topicpreview.display_topic_preview'       => 'inject_whoposted',
		];
	}

	/**
	 * Batch query who-posted data for all topics on the viewforum page
	 */
	public function batch_whoposted($event)
	{
		if ($this->user->data['is_bot'])
		{
			return;
		}

		$topic_ids = $event['topic_list'];
		if (empty($topic_ids))
		{
			return;
		}

		$this->fetch_whoposted($topic_ids);
	}

	/**
	 * Batch query who-posted data for search results (topics mode only)
	 */
	public function batch_whoposted_search($event)
	{
		if ($this->user->data['is_bot'] || $event['show_results'] !== 'topics')
		{
			return;
		}

		$topic_ids = array_column($event['rowset'], 'topic_id');
		if (empty($topic_ids))
		{
			return;
		}

		$this->fetch_whoposted($topic_ids);
	}

	/**
	 * Batch query who-posted data for Recent Topics extension
	 */
	public function batch_whoposted_recenttopics($event)
	{
		if ($this->user->data['is_bot'])
		{
			return;
		}

		$topic_ids = $event['topic_list'];
		if (empty($topic_ids))
		{
			return;
		}

		$this->fetch_whoposted($topic_ids);
	}

	/**
	 * Batch query who-posted data for Similar Topics extension
	 */
	public function batch_whoposted_similartopics($event)
	{
		if ($this->user->data['is_bot'])
		{
			return;
		}

		$topic_ids = array_column($event['rowset'], 'topic_id');
		if (empty($topic_ids))
		{
			return;
		}

		$this->fetch_whoposted($topic_ids);
	}

	/**
	 * Inject who-posted badges into the topic preview popup
	 */
	public function inject_whoposted($event)
	{
		$row = $event['row'];
		$block = $event['block'];
		$topic_id = (int) $row['topic_id'];

		if (empty($this->whoposted_data[$topic_id]))
		{
			return;
		}

		if (!function_exists('get_username_string'))
		{
			include($this->root_path . 'includes/functions_content.' . $this->php_ext);
		}

		// Build badges HTML
		$badges = '<div class="whoposted_badges">';
		foreach ($this->whoposted_data[$topic_id] as $poster)
		{
			if ($poster['user_id'] == ANONYMOUS)
			{
				$username = get_username_string('no_profile', $poster['user_id'], $poster['username'], $poster['user_colour'], $poster['post_username']);
			}
			else
			{
				$username = get_username_string('full', $poster['user_id'], $poster['username'], $poster['user_colour']);
			}

			$badges .= '<span class="whoposted_badge">';
			$badges .= '<span class="whoposted_name">' . $username . '</span>';
			$badges .= '<span class="whoposted_count">' . $poster['posts'] . '</span>';
			$badges .= '</span>';
		}
		$badges .= '</div>';

		// Close the parent div (.topic_preview_last or .topic_preview_first),
		// add <hr> + badges as siblings, then open an empty div to absorb
		// the closing </div> that the macro will generate.
		$inject = '</div><div class="topic_preview_break"></div><hr>' . $badges . '<div style="display:none">';

		if (!empty($block['TOPIC_PREVIEW_LAST_POST']))
		{
			$block['TOPIC_PREVIEW_LAST_POST'] .= $inject;
		}
		else if (!empty($block['TOPIC_PREVIEW_FIRST_POST']))
		{
			$block['TOPIC_PREVIEW_FIRST_POST'] .= $inject;
		}

		$event['block'] = $block;
	}

	/**
	 * Fetch who-posted data for a list of topic IDs
	 *
	 * @param array $topic_ids
	 */
	protected function fetch_whoposted(array $topic_ids)
	{
		$sql_ary = [
			'SELECT'    => 'p.topic_id, COUNT(DISTINCT p.post_id) as posts, u.username, u.user_id, u.user_colour, p.post_username',
			'FROM'      => [POSTS_TABLE => 'p'],
			'LEFT_JOIN' => [[
				'FROM' => [USERS_TABLE => 'u'],
				'ON'   => 'p.poster_id = u.user_id',
			]],
			'WHERE'     => $this->db->sql_in_set('p.topic_id', $topic_ids)
				. ' AND p.post_visibility = ' . ITEM_APPROVED,
			'GROUP_BY'  => 'p.topic_id, u.user_id, p.post_username',
			'ORDER_BY'  => "p.topic_id, CASE WHEN u.user_id IS NULL OR u.username_clean = 'anonymous' THEN 1 ELSE 0 END, posts DESC, u.username_clean ASC",
		];

		$sql = $this->db->sql_build_query('SELECT', $sql_ary);
		$result = $this->db->sql_query($sql);

		$counts = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tid = (int) $row['topic_id'];
			if (!isset($counts[$tid]))
			{
				$counts[$tid] = 0;
			}

			// Limit to 10 posters per topic
			if ($counts[$tid] < 10)
			{
				$this->whoposted_data[$tid][] = [
					'user_id'     => (int) $row['user_id'],
					'username'    => $row['username'],
					'user_colour' => $row['user_colour'],
					'post_username' => $row['post_username'],
					'posts'       => (int) $row['posts'],
				];
			}
			$counts[$tid]++;
		}
		$this->db->sql_freeresult($result);
	}
}
