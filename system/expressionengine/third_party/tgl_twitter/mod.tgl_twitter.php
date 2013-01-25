<?php if (! defined('BASEPATH'))
{
    exit('No direct script access allowed');
}

require_once PATH_THIRD . 'tgl_twitter/classes/twitteroauth.php';

/**
 * Tgl_twitter
 *
 * This is nearly an identical port of the Ellislabs Twitter Timeline Add-on (http://expressionengine.com/downloads/details/twitter_timeline/)
 * with slight modifications to a few methods to support REST Twitter API access vs. the Streaming Twitter API.  Some of the method names with
 * relation to cURL may be incorrect now that we are using oAuth.  Not really sure if oAuth also uses curl..
 *
 * @package default
 * @author  Derek Jones
 * @author  Bryant Hughes
 */

class Tgl_twitter
{
    var $return_data = '';
    var $api_version = '1.1';
    var $base_url = 'http://api.twitter.com/';
    var $cache_name = 'twitter_timeline_cache';
    var $cache_expired = FALSE;
    var $rate_limit_hit = FALSE;
    var $refresh = 45; // Period between cache refreshes, in minutes (purposely high default to prevent hitting twitter's rate limit on shared IPs - be careful)
    var $limit = 20;
    var $parameters = array();
    var $months = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
    var $entities = array('user_mentions' => FALSE, 'urls' => FALSE, 'hashtags' => FALSE, 'media' => FALSE);
    var $use_stale;
    var $time_limit = 5; // Max time in seconds to allow curl/fsockopen connection.
    var $screen_name = '';

    /**
     * Constructor
     *
     * @access    public
     */
    function Tgl_twitter()
    {
        // Adding an old-style constructor allows use on older installs of EE2.
        Tgl_twitter::__construct();
    }

    /**
     * Constructor
     *
     * @access    public
     */
    function __construct()
    {
        $this->EE =& get_instance();

        // Fetch parameters

        $this->refresh     = $this->EE->TMPL->fetch_param('twitter_refresh', $this->refresh);
        $this->limit       = $this->EE->TMPL->fetch_param('limit', $this->limit);
        $this->use_stale   = $this->EE->TMPL->fetch_param('use_stale_cache', 'yes');
        $this->screen_name = $this->EE->TMPL->fetch_param('screen_name');
        $this->api_version = $this->EE->TMPL->fetch_param('api', '1.1');
        $create_links      = $this->EE->TMPL->fetch_param('create_links', '');
        $link_target       = $this->EE->TMPL->fetch_param('link_target', '');

        // create_links="user_mentions|hashtags|urls|media"
        $create_links = explode('|', $create_links);

        foreach ($create_links as $name)
        {
            if (isset($this->entities[$name]))
            {
                $this->entities[$name]                = TRUE;
                $this->parameters['include_entities'] = 'true';
            }
        }

        // timeline type

        $timeline  = 'public';
        $log_extra = '';

        if ($this->screen_name)
        {
            $timeline  = 'user';
            $log_extra = "For User {$this->screen_name}";

            $this->parameters['screen_name'] = $this->screen_name;
        }

        $this->EE->TMPL->log_item("Using '{$timeline}' Twitter Timeline {$log_extra}");

        // build url
        $this->base_url = $this->base_url . $this->api_version . '/statuses/';
        $url            = $this->base_url . $timeline . '_timeline';
        $url .= $this->api_version != '1' ? '.json' : '.xml';

        if (count($this->parameters))
        {
            $qs = '?';

            foreach ($this->parameters as $k => $v)
            {
                $qs .= urlencode($k) . '=' . urlencode($v) . '&';
            }

            $url .= rtrim($qs, '&');
        }

        // retrieve statuses

        $statuses = $this->_fetch_data($url);

        if (! $statuses)
        {
            return;
        }

        // Some variables needed for the parsing process

        $count      = 0;
        $created_at = array();

        // parse created_at date variables outside of the loop to save processing

        if (preg_match_all("/" . LD . "(user_)?created_at\s+format=(\042|\047)([^\\2]*?)\\2" . RD . "/s", $this->EE->TMPL->tagdata, $matches))
        {
            for ($i = 0; $i < count($matches['0']); $i ++)
            {
                $matches['0'][$i]              = str_replace(array(LD, RD), '', $matches['0'][$i]);
                $created_at[$matches['0'][$i]] = $this->EE->localize->fetch_date_params($matches['3'][$i]);
            }
        }

        // Loop through all statuses and do our template replacements

        foreach ($statuses as $key => $val)
        {
            $tagdata = $this->EE->TMPL->tagdata;
            $count ++;

            if ($count > $this->limit)
            {
                return;
            }

            // Link up anything that needs to be linked up

            if (isset($val['entities']) && is_array($val['entities']))
            {
                $find    = array();
                $replace = array();

                foreach ($val['entities'] as $type => $found)
                {
                    if (! isset($this->entities[$type]) OR ! $this->entities[$type])
                    {
                        continue;
                    }

                    foreach ($found as $info)
                    {
                        switch ($type)
                        {
                            case 'user_mentions':
                                $find[]    = '@' . $info['screen_name'];
                                $replace[] = "<a target='" . $link_target . "' title='{$info['name']}' href='http://twitter.com/{$info['screen_name']}'>@{$info['screen_name']}</a>";
                                break;
                            case 'hashtags':
                                $find[]    = '#' . $info['text'];
                                $replace[] = "<a target='" . $link_target . "' title='Search for {$info['text']}' href='http://twitter.com/search?q=%23{$info['text']}'>#{$info['text']}</a>";
                                break;
                            case 'urls':
                                $find[]    = $info['url'];
                                $replace[] = "<a target='" . $link_target . "' title='{$info['expanded_url']}' href='{$info['url']}'>{$info['url']}</a>";
                                break;
                            case 'media':
                                $find[]    = $info['url'];
                                $replace[] = "<a target='" . $link_target . "' title='{$info['expanded_url']}' href='{$info['url']}'>{$info['url']}</a>";
                                break;
                            default:
                                break;
                        }
                    }
                }

                $val['text'] = str_replace($find, $replace, $val['text']);

                unset($find, $replace);
            }

            // Add count

            $val['count'] = $count;

            // Clean the tweet

            $val['text'] = $this->EE->security->xss_clean($val['text']);
            $val['text'] = $this->EE->functions->encode_ee_tags($val['text'], TRUE);

            // XSS cleaning will convert the hash entity in the hashtag link to a literal,
            // we need to put it back

            $val['text'] = str_replace('search?q=#', 'search?q=%23', $val['text']);

            // Prep conditionals

            $cond    = $val;
            $tagdata = $this->EE->functions->prep_conditionals($tagdata, $cond['user']);

            unset($cond['user']);
            $tagdata = $this->EE->functions->prep_conditionals($tagdata, $cond);

            // Parse all found variables

            foreach ($this->EE->TMPL->var_single as $var_key => $var_val)
            {
                // parse {switch} variable

                if (preg_match("/^switch\s*=.+/i", $var_key))
                {
                    $sparam = $this->EE->functions->assign_parameters($var_key);

                    $sw = '';

                    if (isset($sparam['switch']))
                    {
                        $sopt = explode("|", $sparam['switch']);

                        $sw = $sopt[($count - 1 + count($sopt)) % count($sopt)];
                    }

                    $tagdata = $this->EE->TMPL->swap_var_single($var_key, $sw, $tagdata);
                }

                // parse {created_at}

                if (isset($created_at[$var_key]))
                {
                    $date = ($var_key[0] == 'u') ? $statuses[$key]['user']['user_created_at'] : $statuses[$key]['created_at'];

                    $human_time = $this->_parse_twitter_date($date);

                    // We already have GMT so we need $this->EE->localize->convert_human_date_to_gmt to
                    // NOT do any localization.  Fib the Session userdata for sec.
                    $dst      = $this->EE->session->userdata['daylight_savings'];
                    $timezone = $this->EE->session->userdata['timezone'];

                    $this->EE->session->userdata['timezone']         = 'UTC';
                    $this->EE->session->userdata['daylight_savings'] = 'n';

                    $date = $this->EE->localize->convert_human_date_to_gmt($human_time);

                    // reset Session userdata to original values
                    $this->EE->session->userdata['timezone']         = $timezone;
                    $this->EE->session->userdata['daylight_savings'] = $dst;

                    foreach ($created_at[$var_key] as $dvar)
                    {
                        $var_val = str_replace($dvar, $this->EE->localize->convert_timestamp($dvar, $date, TRUE), $var_val);
                    }

                    $tagdata = $this->EE->TMPL->swap_var_single($var_key, $var_val, $tagdata);
                }

                // Parse {status_relative_date}

                if ($var_key == 'status_relative_date')
                {
                    $human_time = $this->_parse_twitter_date($statuses[$key]['created_at']);

                    $date    = $this->EE->localize->set_server_time($this->EE->localize->convert_human_date_to_gmt($human_time));
                    $tagdata = $this->EE->TMPL->swap_var_single($var_key, $this->_get_retative_date($date), $tagdata);
                }

                // Parse all others, main array, user array, all others

                if (isset($val[$var_key]))
                {
                    $tagdata = $this->EE->TMPL->swap_var_single($var_key, $val[$var_key], $tagdata);
                }
                elseif (isset($val['user'][$var_key]))
                {
                    $tagdata = $this->EE->TMPL->swap_var_single($var_key, $val['user'][$var_key], $tagdata);
                }
                else
                {
                    $tagdata = $this->EE->TMPL->swap_var_single($var_key, '', $tagdata);
                }
            }

            $this->return_data .= $tagdata;
        }
    }

    // --------------------------------------------------------------------

    /**
     * Returns a fuzzy relative date based on the tweet date
     *
     * @access    public
     *
     * @param    int
     *
     * @return    string
     */
    private function _get_retative_date($date)
    {
        $this->EE->load->helper('inflector_helper');

        $diff = time() - $date;

        // Units and numbers we divide time by to get them
        $units = array(
            'second' => 60,
            'minute' => 60,
            'hour'   => 24,
            'day'    => 7,
            'week'   => 4,
            'month'  => 12,
            'year'   => 0
        );

        // Get the highest unit this tweet is in
        foreach ($units as $unit => $value)
        {
            if ($diff < $value OR $unit == 'year')
            {
                return $diff . ' ' . $this->EE->lang->line(($diff > 1) ? plural($unit) : $unit);
            }

            // Diff isn't small enough yet, divide it by the current value
            $diff = round($diff / $value);
        }

        return FALSE;
    }

    // --------------------------------------------------------------------

    /**
     * Fetch data
     *
     * Grabs and parses the Twitter status messages
     *
     * @access    public
     * @return    array
     */
    function _fetch_data($url)
    {
        $rawxml     = '';
        $cached_xml = $this->_check_cache($url);

        if ($this->cache_expired OR ! $cached_xml)
        {
            $this->EE->TMPL->log_item("Fetching Twitter timeline remotely");

            if (function_exists('curl_init'))
            {
                $rawxml = $this->_curl_fetch($url);
            }
            else
            {
                $rawxml = $this->_fsockopen_fetch($url);
            }
        }

        // Attempt to parse the data we have
        $xml_obj = $this->api_version != '1' ? $this->_check_json($rawxml) : $this->_check_xml($rawxml);

        if (! $xml_obj)
        {
            // Did we try to grab new data? Tell them that it failed.
            if (! $cached_xml OR $this->cache_expired)
            {
                $this->EE->TMPL->log_item("Twitter Timeline Error: Unable to retrieve statuses from Twitter.com");

                // Rate limit hit and no cached data?
                // We definitely need to write a cache file so we don't continue
                // to ask Twitter for data on every request.

                if (! $cached_xml && $this->rate_limit_hit)
                {
                    $this->_write_cache($rawxml, $url);
                }

                // Try to parse cache? Is it worth it?
                if ($this->use_stale != 'yes' OR ! $cached_xml)
                {
                    return FALSE;
                }

                $this->EE->TMPL->log_item("Twitter Timeline Using Stale Cache: " . $url);
            }
            else
            {
                $this->EE->TMPL->log_item("Twitter Timeline Retrieved From Cache.");
            }

            // Check the cache
            $xml_obj = $this->api_version != '1' ? $this->_check_json($cached_xml) : $this->_check_xml($cached_xml);

            // If we're hitting twitter's rate limit,
            // refresh the cache timestamp, even if the cache file
            // is the rate limiting message. We need to stop asking for data for a while.

            if ($this->rate_limit_hit && $this->cache_expired)
            {
                $this->_write_cache($cached_xml, $url);
            }

            if (! $xml_obj)
            {
                $this->EE->TMPL->log_item("Twitter Timeline Error: Invalid Cache File");
                return FALSE;
            }
        }
        else
        {
            // We have (valid) new data - cache it
            $this->_write_cache($rawxml, $url);
        }

        // Grab the statuses
        $statuses = $this->api_version != '1' ? $this->_parse_json($xml_obj) : $this->_parse_xml($xml_obj);

        if (! is_array($statuses) OR count($statuses) == 0)
        {
            return FALSE;
        }

        return $statuses;
    }

    // --------------------------------------------------------------------

    /**
     * Check XML
     *
     * Checks the XML for validity and also looks for errors in the data.
     *
     * @access    public
     *
     * @param    object
     *
     * @return    array
     */
    function _check_xml($rawxml)
    {
        if ($rawxml == '' OR substr($rawxml, 0, 5) != "<?xml")
        {
            return FALSE;
        }

        $this->EE->load->library('xmlparser');

        $xml_obj = $this->EE->xmlparser->parse_xml($rawxml);

        if ($xml_obj === FALSE)
        {
            return FALSE;
        }

        // Check for error response
        // Error tag in XML response could be in at least one of two places since
        // Twitter doesn't follow its own documentation here.
        if (isset($xml_obj->children[0]) && $xml_obj->children[0]->tag == 'error')
        {
            $error = $xml_obj->children[0]->value;
        }
        elseif (isset($xml_obj->children[1]) && $xml_obj->children[1]->tag == 'error')
        {
            $error = $xml_obj->children[1]->value;
        }

        if (isset($error))
        {
            $this->rate_limit_hit = TRUE;
            $this->EE->TMPL->log_item("Twitter Timeline error: " . $error);
            return FALSE;
        }

        return $xml_obj;
    }

    // --------------------------------------------------------------------

    /**
     * Check JSON
     *
     * Checks the JSON for validity and also looks for errors in the data.
     *
     * @access    public
     *
     * @param    object
     *
     * @return    array
     */
    function _check_json($raw_json)
    {
        if (isset($raw_json->errors) && ! empty($raw_json->errors))
        {
            foreach ($raw_json->errors as $error)
            {
                if ($error->code == 88 || $error->message == "Rate limit exceeded")
                {
                    $this->rate_limit_hit = TRUE;
                }
                $this->EE->TMPL->log_item("Twitter Timeline error: " . $error->message);
            }
            return FALSE;
        }
        return $raw_json;
    }

    // --------------------------------------------------------------------

    /**
     * Parse XML
     *
     * Preps the Twitter returned xml data
     *
     * @access    public
     *
     * @param    object
     *
     * @return    array
     */
    function _parse_xml($xml_obj)
    {
        if (! is_array($xml_obj->children) OR count($xml_obj->children) == 0)
        {
            return FALSE;
        }

        $statuses = array();

        foreach ($xml_obj->children as $key => $status)
        {
            foreach ($status->children as $ckey => $item)
            {
                if ($item->tag == 'user')
                {
                    foreach ($item->children as $ukey => $uitem)
                    {
                        // prefix user's id and created_at so we don't conflict
                        if ($uitem->tag == 'id' OR $uitem->tag == 'created_at')
                        {
                            $uitem->tag = 'user_' . $uitem->tag;
                        }

                        $statuses[$key][$item->tag][$uitem->tag] = $uitem->value;
                    }
                }
                elseif ($item->tag == 'entities')
                {
                    $statuses[$key][$item->tag] = $this->_parse_entities($item->children);
                }
                else
                {
                    $statuses[$key][$item->tag] = $item->value;
                }
            }
        }

        return $statuses;
    }

    // --------------------------------------------------------------------

    /**
     * Parse Json
     *
     * Type converts all JSON objects to array for compactibility with the good old xml based timeline
     *
     * @access    public
     *
     * @param    object
     *
     * @return    array
     */
    function _parse_json($json_obj)
    {

        // the json only needs recurrsively parsing to an array to enhance compactibillity
        if (! is_array($json_obj) OR count($json_obj) == 0)
        {
            return FALSE;
        }

        $statuses = array();
        foreach ($json_obj as $tweet_obj)
        {
            $statuses[] = $this->_object_to_array($tweet_obj);
        }

        return $statuses;
    }

    /**
     * Object to array
     *
     * Type converts all objects to array, helper for the _parse_json method
     *
     * @access    private
     *
     * @param    object
     *
     * @return    array
     */
    private function _object_to_array($obj)
    {
        if (is_object($obj))
        {
            $obj = (array) $obj;
        }
        if (is_array($obj))
        {
            $new = array();
            foreach ($obj as $key => $val)
            {
                $new[$key] = $this->_object_to_array($val);
            }
        }
        else
        {
            $new = $obj;
        }
        return $new;
    }

    // --------------------------------------------------------------------
    /**
     * Parse Entities
     *
     * Twitter sends links, media, usernames, or hashtags to link up, but the format
     * is a little funny. Here we just makes sure everything we need is in our
     * final status array.
     *
     * @access    public
     *
     * @param    object
     *
     * @return    array
     */
    function _parse_entities($all)
    {
        $entities = array();

        foreach ($all as $entity_types)
        {
            if (! is_array($entity_types->children))
            {
                continue;
            }

            foreach ($entity_types->children as $entity)
            {
                $ent = $entity->attributes;

                foreach ($entity->children as $ckey => $info)
                {
                    $ent[$info->tag] = $info->value;
                }

                $entities[$entity_types->tag][] = $ent;
            }
        }
        return $entities;
    }

    // --------------------------------------------------------------------

    /**
     * Check Cache
     *
     * Check for cached data
     *
     * @access    public
     *
     * @param    string
     * @param    bool    Allow pulling of stale cache file
     *
     * @return    mixed - string if pulling from cache, FALSE if not
     */
    function _check_cache($url)
    {
        // Check for cache directory

        $dir = APPPATH . 'cache/' . $this->cache_name . '/';

        if (! @is_dir($dir))
        {
            return FALSE;
        }

        // Check for cache file

        $file = $dir . md5($url);

        if (! file_exists($file) OR ! ($fp = @fopen($file, 'rb')))
        {
            return FALSE;
        }

        flock($fp, LOCK_SH);

        $cache = @fread($fp, filesize($file));

        flock($fp, LOCK_UN);

        fclose($fp);

        // Grab the timestamp from the first line

        $eol = strpos($cache, "\n");

        $timestamp = substr($cache, 0, $eol);
        $cache     = trim((substr($cache, $eol)));

        if (time() > ($timestamp + ($this->refresh * 60)))
        {
            $this->cache_expired = TRUE;
        }

        // Convert the json data if needed
        $json_data = json_decode($cache);
        if (json_last_error() == JSON_ERROR_NONE)
        {
            $cache = $json_data;
        }

        return $cache;
    }

    // --------------------------------------------------------------------

    /**
     * Write Cache
     *
     * Write the cached data
     *
     * @access    public
     *
     * @param    string
     *
     * @return    void
     */
    function _write_cache($data, $url)
    {
        // Convert the json data if needed
        $json_data = json_encode($data);
        if (json_last_error() == JSON_ERROR_NONE && ! empty($json_data))
        {
            $data = $json_data;
        }

        // Check for cache directory

        $dir = APPPATH . 'cache/' . $this->cache_name . '/';

        if (! @is_dir($dir))
        {
            if (! @mkdir($dir, 0777))
            {
                return FALSE;
            }

            @chmod($dir, 0777);
        }

        // add a timestamp to the top of the file
        $data = time() . "\n" . $data;

        // Write the cached data

        $file = $dir . md5($url);

        if (! $fp = @fopen($file, 'wb'))
        {
            return FALSE;
        }

        flock($fp, LOCK_EX);
        fwrite($fp, $data);
        flock($fp, LOCK_UN);
        fclose($fp);

        @chmod($file, 0777);
    }

    // --------------------------------------------------------------------

    /**
     * curl Fetch
     *
     * Fetch Twitter statuses using cURL
     *
     * @access    public
     *
     * @param    string
     *
     * @return    string
     */
    function _curl_fetch($url)
    {
        $data = '';

        //this is where we have modified the plugin to fetch our data via oauth

        $this->EE->load->model('tgl_twitter_model');
        $settings = $this->EE->tgl_twitter_model->get_settings();

        // Read in our saved access token/secret
        $access_token        = $settings['access_token'];
        $access_token_secret = $settings['access_token_secret'];

        // Create our twitter API object
        $oauth         = new TwitterOAuth($settings['consumer_key'], $settings['consumer_secret'], $this->api_version, $access_token, $access_token_secret);
        $oauth->format = $this->api_version != '1' ? 'json' : 'xml';

        $params = array('include_rts' => 'true', 'include_entities' => 'true', 'screen_name' => $this->screen_name);
        $data   = $oauth->get("statuses/user_timeline", $params);

        return $data;
    }

    // --------------------------------------------------------------------

    /**
     * fsockopen Fetch
     *
     * Fetch Twitter statuses using fsockopen
     *
     * @access    public
     *
     * @param    string
     *
     * @return    string
     */
    function _fsockopen_fetch($url)
    {
        $target = parse_url($url);

        $data = '';

        $fp = fsockopen($target['host'], 80, $error_num, $error_str, 8);

        if (is_resource($fp))
        {
            fputs($fp, "GET {$url} HTTP/1.0\r\n");
            fputs($fp, "Host: {$target['host']}\r\n");
            fputs($fp, "User-Agent: EE/EllisLab PHP/" . phpversion() . "\r\n\r\n");

            $headers = TRUE;

            while (! feof($fp))
            {
                $line = fgets($fp, 4096);

                if ($headers === FALSE)
                {
                    $data .= $line;
                }
                elseif (trim($line) == '')
                {
                    $headers = FALSE;
                }
            }

            fclose($fp);
        }

        return $data;
    }

    // --------------------------------------------------------------------

    /**
     * Parse Twitter Date
     *
     * Reformats Twitter's dates to a standard human time notation
     * Twitter's dates are in the format: Fri Apr 13 15:34:45 +0000 2007
     * Returns in YYYY-MM-DD HH:MM:SS
     *
     * @access    public
     *
     * @param    string
     *
     * @return    string
     */
    function _parse_twitter_date($str)
    {
        $parts = explode(' ', $str);
        $month = array_keys($this->months, $parts[1]);
        $mm    = sprintf("%02s", $month[0] + 1);

        return "{$parts[5]}-{$mm}-{$parts[2]} {$parts[3]}";
    }
}

/* End of File: mod.module.php */
