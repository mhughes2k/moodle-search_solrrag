<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Solr search engine settings.
 *
 * @package    search_solrrag
 * @copyright  2015 Daniel Neis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . "/search/engine/solrrag/lib.php");
if ($ADMIN->fulltree) {

    if (!during_initial_install()) {
        if (!function_exists('solr_get_version')) {
            $settings->add(new admin_setting_heading('search_solrrag_settings', '', get_string('extensionerror', 'search_solrrag')));

        } else {
            // Which AI Provider to use:
            $settings->add(new admin_setting_heading('search_solrrag_aiprovider',
                new lang_string('aisettings', 'ai'), ''));
            $providers = \core_ai\api::get_providers(
                null,
                true,
                true
            );
            $optproviders = [
                '' => get_string('disable', 'ai')
            ];

            foreach($providers as $provider) {
                $optproviders[$provider->get('id')] = $provider->get('name');
            }
            $settings->add(new admin_setting_configselect(
                'search_solrrag/aiprovider',
                'Choose Provider',
                'List of available AI services',
                "",
                $optproviders
            ));
            $optextractors = [
                "solrtika" => "Solr with internal Tika",
                'tika' => "Standalone Tika"
            ];
            $settings->add(new admin_setting_configselect(
                'search_solrrag/extractor',
                'Choose File Content extractor',
                'List of File Content Extractors',
                "",
                $optextractors
            ));
            $settings->add(new admin_setting_configtext('search_solrrag/extractorurl',
                new lang_string('extractorpath', 'search_solrrag'),
                new lang_string('extractorpath_desc', 'search_solrrag'), '', PARAM_RAW));

            $settings->add(new admin_setting_heading('search_solrrag_connection',
                new lang_string('connectionsettings', 'search_solrrag'), ''));
            $settings->add(new admin_setting_configtext('search_solrrag/server_hostname', new lang_string('solrserverhostname', 'search_solrrag'), new lang_string('solrserverhostname_desc', 'search_solrrag'), '127.0.0.1', PARAM_HOST));
            $settings->add(new admin_setting_configtext('search_solrrag/indexname', new lang_string('solrindexname', 'search_solrrag'), '', '', PARAM_ALPHANUMEXT));
            $settings->add(new admin_setting_configcheckbox('search_solrrag/secure', new lang_string('solrsecuremode', 'search_solrrag'), '', 0, 1, 0));

            $secure = get_config('search_solrrag', 'secure');
            $defaultport = !empty($secure) ? 8443 : 8983;
            $settings->add(new admin_setting_configtext('search_solrrag/server_port', new lang_string('solrhttpconnectionport', 'search_solrrag'), '', $defaultport, PARAM_INT));
            $settings->add(new admin_setting_configtext('search_solrrag/server_username', new lang_string('solrauthuser', 'search_solrrag'), '', '', PARAM_RAW));
            $settings->add(new admin_setting_configpasswordunmask('search_solrrag/server_password', new lang_string('solrauthpassword', 'search_solrrag'), '', ''));
            $settings->add(new admin_setting_configtext('search_solrrag/server_timeout', new lang_string('solrhttpconnectiontimeout', 'search_solrrag'), new lang_string('solrhttpconnectiontimeout_desc', 'search_solrrag'), 30, PARAM_INT));
            $settings->add(new admin_setting_configtext('search_solrrag/ssl_cert', new lang_string('solrsslcert', 'search_solrrag'), new lang_string('solrsslcert_desc', 'search_solrrag'), '', PARAM_RAW));
            $settings->add(new admin_setting_configtext('search_solrrag/ssl_key', new lang_string('solrsslkey', 'search_solrrag'), new lang_string('solrsslkey_desc', 'search_solrrag'), '', PARAM_RAW));
            $settings->add(new admin_setting_configpasswordunmask('search_solrrag/ssl_keypassword', new lang_string('solrsslkeypassword', 'search_solrrag'), new lang_string('solrsslkeypassword_desc', 'search_solrrag'), ''));
            $settings->add(new admin_setting_configtext('search_solrrag/ssl_cainfo', new lang_string('solrsslcainfo', 'search_solrrag'), new lang_string('solrsslcainfo_desc', 'search_solrrag'), '', PARAM_RAW));
            $settings->add(new admin_setting_configtext('search_solrrag/ssl_capath', new lang_string('solrsslcapath', 'search_solrrag'), new lang_string('solrsslcapath_desc', 'search_solrrag'), '', PARAM_RAW));

            $settings->add(new admin_setting_heading('search_solrrag_fileindexing',
                new lang_string('fileindexsettings', 'search_solrrag'), ''));
            $settings->add(new admin_setting_configcheckbox('search_solrrag/fileindexing',
                new lang_string('fileindexing', 'search_solrrag'),
                new lang_string('fileindexing_help', 'search_solrrag'), 1));
            $settings->add(new admin_setting_configtext('search_solrrag/maxindexfilekb',
                new lang_string('maxindexfilekb', 'search_solrrag'),
                new lang_string('maxindexfilekb_help', 'search_solrrag'), '2097152', PARAM_INT));

            // Alternate connection.
            $settings->add(new admin_setting_heading('search_solrrag_alternatesettings',
                new lang_string('searchalternatesettings', 'admin'),
                new lang_string('searchalternatesettings_desc', 'admin')));
            $settings->add(new admin_setting_configtext('search_solrrag/alternateserver_hostname',
                new lang_string('solrserverhostname', 'search_solrrag'),
                new lang_string('solrserverhostname_desc', 'search_solrrag'), '127.0.0.1', PARAM_HOST));
            $settings->add(new admin_setting_configtext('search_solrrag/alternateindexname',
                new lang_string('solrindexname', 'search_solrrag'), '', '', PARAM_ALPHANUMEXT));
            $settings->add(new admin_setting_configcheckbox('search_solrrag/alternatesecure',
                new lang_string('solrsecuremode', 'search_solrrag'), '', 0, 1, 0));

            $secure = get_config('search_solrrag', 'alternatesecure');
            $defaultport = !empty($secure) ? 8443 : 8983;
            $settings->add(new admin_setting_configtext('search_solrrag/alternateserver_port',
                new lang_string('solrhttpconnectionport', 'search_solrrag'), '', $defaultport, PARAM_INT));
            $settings->add(new admin_setting_configtext('search_solrrag/alternateserver_username',
                new lang_string('solrauthuser', 'search_solrrag'), '', '', PARAM_RAW));
            $settings->add(new admin_setting_configpasswordunmask('search_solrrag/alternateserver_password',
                new lang_string('solrauthpassword', 'search_solrrag'), '', ''));
            $settings->add(new admin_setting_configtext('search_solrrag/alternatessl_cert',
                new lang_string('solrsslcert', 'search_solrrag'),
                new lang_string('solrsslcert_desc', 'search_solrrag'), '', PARAM_RAW));
            $settings->add(new admin_setting_configtext('search_solrrag/alternatessl_key',
                new lang_string('solrsslkey', 'search_solrrag'),
                new lang_string('solrsslkey_desc', 'search_solrrag'), '', PARAM_RAW));
            $settings->add(new admin_setting_configpasswordunmask('search_solrrag/alternatessl_keypassword',
                new lang_string('solrsslkeypassword', 'search_solrrag'),
                new lang_string('solrsslkeypassword_desc', 'search_solrrag'), ''));
            $settings->add(new admin_setting_configtext('search_solrrag/alternatessl_cainfo',
                new lang_string('solrsslcainfo', 'search_solrrag'),
                new lang_string('solrsslcainfo_desc', 'search_solrrag'), '', PARAM_RAW));
            $settings->add(new admin_setting_configtext('search_solrrag/alternatessl_capath',
                new lang_string('solrsslcapath', 'search_solrrag'),
                new lang_string('solrsslcapath_desc', 'search_solrrag'), '', PARAM_RAW));
        }
    }
}
