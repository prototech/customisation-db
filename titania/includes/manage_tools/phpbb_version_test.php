<?php
/**
*
* @package Titania
* @version $Id$
* @copyright (c) 2008 phpBB Customisation Database Team
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

if (!defined('IN_PHPBB'))
{
	exit;
}

class phpbb_version_test
{
	function display_options()
	{
		return array(
			'title'	=> 'PHPBB_VERSION_TEST',
			'vars'	=> array(
				'new_phpbb_version'		=> array('lang' => 'NEW_PHPBB_VERSION', 'type' => 'text:40:255', 'explain' => true),
				'limit_phpbb_version'	=> array('lang' => 'VERSION_RESTRICTION', 'type' => 'select_multiple', 'function' => 'pva_generate_phpbb_version_select', 'explain' => true, 'default' => ''),
			)
		);
	}

	function run_tool()
	{
		$new_phpbb_version = request_var('new_phpbb_version', '');
		$limit_phpbb_versions = request_var('limit_phpbb_version', array(''));

		if (!$new_phpbb_version || strlen($new_phpbb_version) < 5 || $new_phpbb_version[1] != '.' || $new_phpbb_version[3] != '.')
		{
			trigger_back('NO_VERSION_SELECTED');
		}

		// Does the zip for this exist?
		$version = preg_replace('#[^a-zA-Z0-9\.\-]+#', '', $new_phpbb_version);
		if (!file_exists(TITANIA_ROOT . 'store/phpbb_packages/phpBB-' . $version . '.zip'))
		{
			trigger_back(sprintf(phpbb::$user->lang['FILE_NOT_EXIST'], 'store/phpbb_packages/phpBB-' . $version . '.zip'));
		}

		$phpbb_version_branch = (int) $new_phpbb_version[0] . (int) $new_phpbb_version[2];
		$phpbb_version_revision = substr($new_phpbb_version, 4);

		// Is it in our version cache?
		$versions = titania::$cache->get_phpbb_versions();
		if (!isset($versions[$phpbb_version_branch . $phpbb_version_revision]))
		{
			titania::$cache->destroy('_titania_phpbb_versions');
		}

		$testable_types = array();
		foreach (titania_types::$types as $type_id => $type)
		{
			if ($type->automod_test)
			{
				$testable_types[] = $type_id;
			}
		}
		if (!sizeof($testable_types))
		{
			trigger_back('NO_REVISIONS_UPDATED');
		}

		$revisions = array();
		if (sizeof($limit_phpbb_versions) > 1 || (sizeof($limit_phpbb_versions) && $limit_phpbb_versions[0] != 0))
		{
			// phpBB versions limiter
			foreach ($limit_phpbb_versions as $limit_phpbb_version)
			{
				$sql = 'SELECT DISTINCT(rp.contrib_id), rp.revision_id
					FROM ' . TITANIA_REVISIONS_PHPBB_TABLE . ' rp, ' . TITANIA_CONTRIBS_TABLE . ' c, ' . TITANIA_REVISIONS_TABLE . ' r
					WHERE rp.phpbb_version_branch = ' . (int) substr($limit_phpbb_version, 0, 2) . '
						AND rp.phpbb_version_revision = \'' . phpbb::$db->sql_escape(substr($limit_phpbb_version, 2)) . '\'
						AND c.contrib_id = rp.contrib_id
						AND ' . phpbb::$db->sql_in_set('c.contrib_type', $testable_types) . '
						AND r.revision_id = rp.revision_id
					ORDER BY r.revision_validated DESC, r.revision_time DESC';
				$result = phpbb::$db->sql_query($sql);
				while ($row = phpbb::$db->sql_fetchrow($result))
				{
					$revisions[$row['revision_id']] = $row['contrib_id'];
				}
				phpbb::$db->sql_freeresult($result);
			}
		}
		else
		{
			// All
			$sql = 'SELECT DISTINCT(r.contrib_id), r.revision_id FROM ' . TITANIA_REVISIONS_TABLE . ' r, ' . TITANIA_CONTRIBS_TABLE . ' c
				WHERE c.contrib_id = r.contrib_id
					AND ' . phpbb::$db->sql_in_set('c.contrib_type', $testable_types) . '
				ORDER BY r.revision_validated DESC, r.revision_time DESC';
			$result = phpbb::$db->sql_query($sql);
			while ($row = phpbb::$db->sql_fetchrow($result))
			{
				$revisions[$row['revision_id']] = $row['contrib_id'];
			}
			phpbb::$db->sql_freeresult($result);
		}

		if (!sizeof($revisions))
		{
			trigger_back('NO_REVISIONS_UPDATED');
		}

		// Don't include those which already are marked for this phpBB version
		$sql = 'SELECT revision_id FROM ' . TITANIA_REVISIONS_PHPBB_TABLE . '
			WHERE ' . phpbb::$db->sql_in_set('revision_id', array_map('intval', array_keys($revisions))) . '
				AND phpbb_version_branch = ' . $phpbb_version_branch . '
				AND phpbb_version_revision = \'' . phpbb::$db->sql_escape($phpbb_version_revision) . '\'';
		$result = phpbb::$db->sql_query($sql);
		while ($row = phpbb::$db->sql_fetchrow($result))
		{
			unset($revisions[$row['revision_id']]);
		}
		phpbb::$db->sql_freeresult($result);

		if (!sizeof($revisions))
		{
			trigger_back('NO_REVISIONS_UPDATED');
		}

		$sql_ary = array();
		foreach ($revisions as $revision_id => $contrib_id)
		{
			$sql_ary[] = array(
				'revision_id'				=> (int) $revision_id,
				'phpbb_version_branch'		=> $phpbb_version_branch,
				'phpbb_version_revision'	=> $phpbb_version_revision,
			);
		}

		phpbb::$db->sql_multi_insert(TITANIA_AUTOMOD_QUEUE_TABLE, $sql_ary);

		trigger_back(sprintf(phpbb::$user->lang['REVISIONS_ADDED_TO_QUEUE'], sizeof($revisions)));
	}
}

if (!function_exists('pva_generate_phpbb_version_select'))
{
	function pva_generate_phpbb_version_select()
	{
		$versions = titania::$cache->get_phpbb_versions();

		$select = '<option value="0" selected="selected">-----</option>';

		foreach ($versions as $version => $name)
		{
			$select .= '<option value="' . $version . '">' . $name . '</option>';
		}

		return $select;
	}
}