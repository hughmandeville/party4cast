<?php
/**
 * process_party_feeds.php - creates a Google spreadsheet of events in NYC and a JSON file
 *
 *   https://docs.google.com/spreadsheets/d/1sj7QTBQNC71RpTvUT-3dmBwLZoFiigZn_cJeQgXnUgc/
 *   http://muchobliged.tv/party4cast/admin/party4cast_events.json
 *
 * Eventbrite NYC parties - calls Eventbrite search API.
 *   https://www.eventbriteapi.com
 *   https://www.eventbrite.com/d/ny--new-york/parties/?crt=regular&sort=date
 *
 * NightOut New York - calls NighOut search API.
 *   https://nightout.com/api/search.json?timing%5B%5D=week&city=2&page=1
 *   https://nightout.com/ny/new-york
 *
 * NYC Go - calls NYC Go events API.
 *   http://www.nycgo.com/feeds/events/2016-11-13/2016-11-20/1010
 *   http://www.nycgo.com/things-to-do/events-in-nyc/nightlife-calendar
 *
 * NYC.com - parse NYC.com pages.
 *   http://www.nyc.com/concert_ticketselements/?page=2
 *
 * NOTE: Make sure the client email address (fb-video-dashboard@nyt-newsroom-dashboards.iam.gserviceaccount.com)
 * has edit permission on the sheet.  Otherwise will need to update code to impersonate someone.
 *
 * ----
 *
 * To Add
 *   https://www.timeout.com/newyork/bars/bar-openings-and-events-in-nyc
 *   https://www.timeout.com/newyork/nightlife/best-parties-in-nyc-this-week
 *   http://www.villagevoice.com/calendar
 *
 * To Do
 *   Handle image /defaults/posters/medium.png from NightOut NYC
 *   Write to Google spreadsheet in chunks.
 */
ini_set('display_errors', 1);
date_default_timezone_set('America/New_York');
error_reporting(E_ALL|E_STRICT);
ini_set('memory_limit','256M');

$start_time = microtime(true);
$end_time = 0;

$ini_array = parse_ini_file('settings.ini');
$eventbrite_oauth_token = $ini_array['eventbrite_oauth_key'];

require_once __DIR__ . '/../vendor/autoload.php';

// Colors used in spreadsheet.
$color = array();
$color['s1']   = 'FFECB3'; // Amber 100
$color['s2']   = 'FFF8E1'; // Amber 50

// Set Google Sheet ID
$spreadsheet_id = '1sj7QTBQNC71RpTvUT-3dmBwLZoFiigZn_cJeQgXnUgc';
$sheet_id = 0;

print "Process Party Feeds\n";
print "===================\n";
print "Spreadsheet: https://docs.google.com/spreadsheets/d/$spreadsheet_id/\n";

$ch=curl_init();
curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2840.71 Safari/537.36');

$ng_events = get_nycgo_events($ch);
$nc_events = get_nyccom_events($ch);
$no_events = get_nightout_events($ch);
$eb_events = get_evenbrite_events($ch, $eventbrite_oauth_token);
$events = array_merge($ng_events, $nc_events, $no_events, $eb_events);

// TODO: order events by date

// Write to a JSON file
$file_data = array('created' => date('r'),
                   'created_ts' => time(),
                   'num_events' => count($events),
                   'host' => gethostname(),
                   'events' => $events);
                   
$json = json_encode($file_data);
// $file = '/tmp/party4cast_events.json';
$file = '/home/muchob5/public_html/party4cast/admin/party4cast_events.json';
$ret = file_put_contents($file, $json);
$file_data = null;

print "\nWrote data to file $file.\n\n";

// Get the Google Sheets Service.
$service = get_service();

// Clear data in spreadsheet.
print "Clearing sheet...\n\n";
clear_spreadsheet($service, $spreadsheet_id, $sheet_id);


$rows = get_header_rows();
$row_num = 0;
// Write header rows.
write_rows_to_sheet($service, $spreadsheet_id, $sheet_id, $rows, $row_num);
$row_num = count($rows);


printf("Writing event rows to sheet...\n\n");
$chunk_size = 50;
$free_row = 2;
$rows = array();
$cur_event = 0;
foreach($events as $event) {
    $rows[] = get_event_row($event);
    //print  '  ' . $event->name . "\n";
    if ((($cur_event % $chunk_size) == 0) && ($cur_event != 0)) {
        usleep(300000);
        write_rows_to_sheet($service, $spreadsheet_id, $sheet_id, $rows, $free_row);
        printf("  Wrote event rows %4d - %4d.\n", ($free_row + 1), ($free_row + count($rows)));
        $free_row += count($rows);
        $rows = array();
    }
    $cur_event++;
    //break;
}
if (count($rows) > 0) {
    write_rows_to_sheet($service, $spreadsheet_id, $sheet_id, $rows, $free_row);
    printf("  Wrote event rows %4d - %4d.\n", ($free_row + 1), ($free_row + count($rows)));
}
print ("\nWrote " . count($events) . " event rows.\n\n");


$end_time = microtime(true);
$diff_time = $end_time - $start_time;
printf("Took %2.1fs to run and used %s of memory.\n", $diff_time, bytes_to_nice_string(memory_get_usage()));

exit(0);



/**
 * get_nyccom_events - gets NYC.com.
 */
function get_nyccom_events($ch)
{
    $events = array();
    for ($i=2; $i<=12; $i++) {
        usleep(400000);
        $url = 'http://www.nyc.com/concert_ticketselements/?page=' . $i;

        curl_setopt($ch, CURLOPT_URL, $url);
        $response_data = curl_exec($ch);
        if ($response_data != null) {
            $doc = new DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML($response_data);
            $xpath = new DOMXPath($doc);

            $items = $xpath->query('//li[not(contains(@class, "header"))]');

            foreach ($items as $item) {
                $event = new Event();
                $names = $xpath->query('.//h3[@itemprop="name"]', $item);
                if ((!empty($names)) && (!empty($names[0]))) {
                    $event->name = $names[0]->nodeValue;
                } else {
                    continue;
                }

                $date_venues = $xpath->query('.//div[@class="datevenue"]', $item);
                foreach($date_venues as $date_venue) {
                    $start_dates = $xpath->query('.//meta[@itemprop="startDate"]', $date_venue);
                    $start_time = date('r', strtotime($start_dates[0]->getAttribute('content')));
                    $event->start_time = $start_time;
                }

                $venues = $xpath->query('.//span[@itemprop="name"]', $item);
                $event->description = $venues[0]->nodeValue;

                $urls = $xpath->query('.//a[@itemprop="url"]', $item);
                $event->url = $urls[0]->getAttribute('href');
                $event->end_time = '';
                $event->status = '';
                $event->type = '';
                $event->type2 = '';
                $event->image = '';
                $event->feed = 'NYC.com';
                $events[] = $event;
            }
        }
    }
    return ($events);
}


/**
 * get_nycgo_events - gets NYC Go events using their events API.
 */
function get_nycgo_events($ch)
{
    $events = array();
    $start_date = date('Y-m-d');
    $next_week = strtotime('+7 days');
    $end_date = date('Y-m-d', $next_week);
    $url = "http://www.nycgo.com/feeds/events/$start_date/$end_date/1010";
    usleep(400000);

    curl_setopt($ch, CURLOPT_URL, $url);
    $response_data = curl_exec($ch);
    if ($response_data != null) {
        $data = json_decode($response_data, true);
        $event_dates = $data['items'];
        foreach($event_dates as $event_date) {
            $events_data = $event_date['events'];
            foreach ($events_data as $event_data) {
                $event = new Event();
                $event->name = $event_data['title'];
                $event->description = $event_data['description'];
                $event->url = 'http://www.nycgo.com/events/' . $event_data['url'];
                $event->start_time = date("r", ($event_data['startDate']/1000));
                $event->end_time = date("r", ($event_data['endDate']/1000));
                $event->status = '';
                $event->type = $event_data['primaryCategory'];
                $event->type2 = '';
                $event->image = $event_data['image'];
                $event->feed = 'NYC Go';
                $events[] = $event;
            }
        }
    }
    return ($events);
}



/**
 * get_nightout_events - gets NighOut NYC events using their Search API.
 */
function get_nightout_events($ch)
{
    $events = array();

    $page_number = 1;
    $page_count = 0;

    $nightout_url = "https://nightout.com/api/search.json?timing%5B%5D=week&city=2";

    do {
        usleep(400000);

        curl_setopt($ch, CURLOPT_URL, $nightout_url . '&page=' . $page_number);
        $response_data = curl_exec($ch);

        if ($response_data != null) {
            $data = json_decode($response_data, true);
            $page_number = $data['meta']['current_page'];
            $page_count = $data['meta']['total_pages'];
            $events_data = $data['data'];
            foreach($events_data as $event_data) {
                $event = new Event();
                $event->name = $event_data['title'];
                $event->description = $event_data['subtitle'];
                $event->url = "https://nightout.com" . $event_data['url'];
                $event->start_time = date("h:i a", $event_data['start_time']);
                $event->end_time = date("h:i a", $event_data['end_time']);
                $event->status = '';
                if ($event_data['cent_max_price'] > 0) {
                    $event->price = '$' . ($event_data['cent_min_price'] / 100) . ' - $' . ($event_data['cent_max_price'] / 100);
                } else {
                    $event->price = '$' . ($event_data['cent_min_price'] / 100);
                }
                $event->type = $event_data['type_label'];
                $event->type2 = $event_data['item_type'];
                $event->image = $event_data['image_src'];
                $event->feed = 'NightOut NYC';
                $events[] = $event;
            }
        } else {
            break;
        }
        $page_number++;
    } while ($page_number < $page_count);
    return ($events);
}



/**
 * get_eventrite_events - gets NYC parties from Eventbrite API.
 */
function get_evenbrite_events($ch, $eventbrite_oauth_token)
{
    $events = array();

    // Eventbrite parties around New York
    date_default_timezone_set('UTC');
    $in_ten_days = strtotime('+10 days');
    $eventbrite_url = 'https://www.eventbriteapi.com/v3/events/search/?token=' . $eventbrite_oauth_token . '&formats=11&location.latitude=40.720363&location.longitude=-73.948252&location.within=12mi&sort_by=date&start_date.range_end=' . date('Y-m-d', $in_ten_days) . 'T' . date('H:i:s', $in_ten_days) . 'Z';
    date_default_timezone_set('America/New_York');

    $page_number = 1;
    $page_count = 0;

    do {
        usleep(400000);

        curl_setopt($ch, CURLOPT_URL, $eventbrite_url . '&page=' . $page_number);
        $response_data = curl_exec($ch);

        if ($response_data != null) {
            $data = json_decode($response_data, true);
            $pagination = $data['pagination'];
            $page_number = $pagination['page_number'];
            $page_count = $pagination['page_count'];
            $events_data = $data['events'];

            foreach($events_data as $event_data) {
                $event = new Event();
                $event->name = $event_data['name']['text'];
                $event->description = $event_data['description']['text'];
                $event->url = $event_data['url'];
                $event->start_time = $event_data['start']['local'];
                $event->end_time = $event_data['end']['local'];
                $event->status = $event_data['status'];
                $event->feed = 'Eventbrite Parties NYC';
                $events[] = $event;
            }
        } else {
            break;
        }
        $page_number++;
    } while ($page_number < $page_count);
    return ($events);
}


/**
 * Gets Google Sheets service.  Uses newsroom dashboard auth config.
 */
function get_service()
{
    $client = new Google_Client();
    $client->setApplicationName("fb-video-dashboard");
    $client->setAuthConfig(__DIR__ . '/google-project-nyt-video-dashboard.json');
    $client->setAccessType('offline');

    $client->addScope(Google_Service_Sheets::SPREADSHEETS);

    $service = new Google_Service_Sheets($client);

    return $service;
}

/**
 * Clear all the values from the spreadsheet.
 */
function clear_spreadsheet($service, $spreadsheet_id, $sheet_id)
{
    $request = new Google_Service_Sheets_Request();
    $ucRequest = new Google_Service_Sheets_UpdateCellsRequest();
    $range = new Google_Service_Sheets_GridRange();
    $range->setSheetId($sheet_id);
    $range->setStartRowIndex(0);
    $range->setStartColumnIndex(0);
    $ucRequest->setFields('*');
    $ucRequest->setRange($range);
    $request->setUpdateCells($ucRequest);
    $batchUpdate = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest();
    $batchUpdate->setRequests(array($request));
    $result = $service->spreadsheets->batchUpdate($spreadsheet_id, $batchUpdate);
}

/**
 * Get header rows.
 */
function get_header_rows()
{
    global $color;
    $rows = array();
    $row = new Google_Service_Sheets_RowData();
    $cells = array();
    $update_str = 'This script is programmatically updated at 11am every morning.  Last updated ' . date('r') . '.';
    $cells[] = get_cell($update_str,  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // A - 1
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // B - 2
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // C - 3
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // D - 4
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // E - 5
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // F - 6
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // G - 7
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // H - 8
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // I - 9
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // J - 10
    $cells[] = get_cell('',  $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // K - 11
    $row->setValues($cells);
    $rows[] = $row;
    
    $row = new Google_Service_Sheets_RowData();
    $cells = array();
    $cells[] = get_cell('Event Name',  $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // A - 1
    $cells[] = get_cell('Description', $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // B - 2
    $cells[] = get_cell('URL',         $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // C - 3
    $cells[] = get_cell('Start Time',  $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // D - 4
    $cells[] = get_cell('End Time',    $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // E - 5
    $cells[] = get_cell('Status',      $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // F - 6
    $cells[] = get_cell('Price',       $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // G - 7
    $cells[] = get_cell('Type',        $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // H - 8
    $cells[] = get_cell('Type2',       $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // I - 9
    $cells[] = get_cell('Image',       $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // J - 10
    $cells[] = get_cell('Feed',        $color['s1'], true, 12, 'LEFT',   'BOTTOM', 'WRAP');  // K - 11
    $row->setValues($cells);
    $rows[] = $row;

    
    return ($rows);
}


function get_event_row($event)
{
    global $color;
    $row = new Google_Service_Sheets_RowData();
    $cells = array();
    $cells[] = get_cell($event->name,         $color['s2'], false, 10, 'LEFT', 'TOP', 'WRAP', 'string');  // A - 1
    $cells[] = get_cell($event->description,  $color['s2'], false,  8, 'LEFT', 'TOP', 'WRAP', 'string');  // B - 2
    $cells[] = get_cell($event->url,          $color['s2'], false,  9, 'LEFT', 'TOP', 'WRAP', 'string');  // C - 3
    $cells[] = get_cell($event->start_time,   $color['s2'], false, 10, 'LEFT', 'TOP', 'WRAP', 'string');  // D - 4
    $cells[] = get_cell($event->end_time,     $color['s2'], false, 10, 'LEFT', 'TOP', 'WRAP', 'string');  // E - 5
    $cells[] = get_cell($event->status,       $color['s2'], false, 10, 'LEFT', 'TOP', 'WRAP', 'string');  // F - 6
    $cells[] = get_cell($event->price,        $color['s2'], false, 10, 'LEFT', 'TOP', 'WRAP', 'string');  // G - 7
    $cells[] = get_cell($event->type,         $color['s2'], false, 10, 'LEFT', 'TOP', 'WRAP', 'string');  // H - 8
    $cells[] = get_cell($event->type2,        $color['s2'], false, 10, 'LEFT', 'TOP', 'WRAP', 'string');  // I - 9
    $cells[] = get_cell($event->image,        $color['s2'], false,  9, 'LEFT', 'TOP', 'WRAP', 'string');  // J - 10
    $cells[] = get_cell($event->feed,         $color['s2'], false, 10, 'LEFT', 'TOP', 'WRAP', 'string');  // K - 11

    $row->setValues($cells);
    return ($row);
}



/**
 * Writes array of rows to sheet.
 */
function write_rows_to_sheet($service, $spreadsheet_id, $sheet_id, $rows, $start_row)
{
    if (empty($rows)) {
        return;
    }
    $num_rows = count($rows);
    $num_cols = count($rows[0]->values);

    $range = new Google_Service_Sheets_GridRange();
    $range->setSheetId($sheet_id);
    $range->setStartRowIndex($start_row);
    $range->setEndRowIndex($start_row + $num_rows);
    $range->setStartColumnIndex(0);
    $range->setEndColumnIndex($num_cols);

    $cellsRequest = new Google_Service_Sheets_UpdateCellsRequest();
    $cellsRequest->setFields('*');
    $cellsRequest->setRange($range);
    $cellsRequest->setRows($rows);
    $request = new Google_Service_Sheets_Request();
    $request->setUpdateCells($cellsRequest);

    $batchUpdate = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest();
    $batchUpdate->setRequests(array($request));
    // retry 3 times if batch update fails
    for ($i = 1; $i <= 3; $i++) {
        try {
            $result = $service->spreadsheets->batchUpdate($spreadsheet_id, $batchUpdate);
            break;
        } catch (Google_Service_Exception $gse) {
            usleep(1000000 * $i);
            print "  Got exception updating spreadsheet (try $i). " . $gse->getMessage() . "\n";
        }
    }
}



/**
 * Given color string (e.g. EDE7F6) returns Google_Service_Sheets_Color.
 */
function get_color($rgb)
{
    $red = substr($rgb, 0, 2);
    $green = substr($rgb, 2, 2);
    $blue = substr($rgb, 4, 2);

    $color = new Google_Service_Sheets_Color();
    $color->setAlpha(0);
    $color->setRed((hexdec($red) / 255));
    $color->setGreen((hexdec($green) / 255));
    $color->setBlue((hexdec($blue) / 255));

    return ($color);
}


function get_cell($val, $bg_color, $bold, $font_size, $halign, $valign, $wrap, $type = 'string')
{
    $cellData = new Google_Service_Sheets_CellData();
    $value = new Google_Service_Sheets_ExtendedValue();
    $cell_format = new Google_Service_Sheets_CellFormat();

    if ($type == 'int') {
        $value->setNumberValue($val);
        $number_format = new Google_Service_Sheets_NumberFormat();
        $number_format->setPattern("#,##0");
        $number_format->setType("Number");
        $cell_format->setNumberFormat($number_format);
    } elseif ($type == 'float') {
        $value->setNumberValue($val);
        $number_format = new Google_Service_Sheets_NumberFormat();
        $number_format->setPattern("#,##0.00");
        $number_format->setType("Number");
        $cell_format->setNumberFormat($number_format);
    } elseif ($type == 'number') {
        $value->setNumberValue($val);
    } elseif ($type == 'percent') {
        $value->setNumberValue($val);
        $number_format = new Google_Service_Sheets_NumberFormat();
        $number_format->setPattern("#0.00%");
        $number_format->setType("Number");
        $cell_format->setNumberFormat($number_format);
    } else {
        $value->setStringValue($val);
    }


    $g_bg_color = get_color($bg_color);
    $g_font_color = get_color('212121');

    $text_format = new Google_Service_Sheets_TextFormat();
    $text_format->setBold($bold);
    $text_format->setFontSize($font_size);
    $text_format->setForegroundColor($g_font_color);
    $cell_format->setBackgroundColor($g_bg_color);
    $cell_format->setHorizontalAlignment($halign);
    $cell_format->setVerticalAlignment($valign);
    $cell_format->setWrapStrategy($wrap);
    $cell_format->setTextFormat($text_format);

    $cellData->setUserEnteredFormat($cell_format);
    $cellData->setUserEnteredValue($value);

    return ($cellData);
}

/**
 * Given array and key (or index), returns the value.
 * If nothing set, returns empty string, without throwing exception.
 */
function get_str_value($array, $field)
{
    if (!empty($array[$field])) {
        // remove tabs and line breaks
        $ret = $array[$field];
        $ret = str_replace("\t", ' ', $ret);
        $ret = str_replace("\n", ' ', $ret);
        return ($ret);
    }
    return ('');
}


/**
 * Return the nice column name string (e.g. AB) given the column number (e.g. 28).
 */
function get_column_name($num)
{
    // A = 65 ASCII
    $num--;
    $prefix = '';
    $prefix_num = floor($num / 26);
    if ($prefix_num > 0) {
        $prefix = chr($prefix_num + 64);
    }
    $col_num = $prefix . chr(($num % 26) + 65);
    return ($col_num);
}



/**
 * Given number of bytes returns nice string representing size (1.1 mb).
 */
function bytes_to_nice_string($bytes)
{
    $nice_str = "";
    if ($bytes > (1024 * 1024 * 1024)) {
        $nice_str = sprintf ("%2.1f gb", $bytes / (1024 * 1024 * 1024));
    } elseif ($bytes > (1024 * 1024)) {
        $nice_str = sprintf ("%2.1f mb", $bytes / (1024 * 1024));
    } elseif ($bytes > 1024) {
        $nice_str = sprintf ("%2.1f k", $bytes / 1024);
    } else {
        $nice_str = sprintf ("%d b", $bytes);
    }
    return ($nice_str);
}


/**
 * Event class.
 */
class Event
{
    public $name = '';
    public $description = '';
    public $url = '';
    public $start_time = '';
    public $end_time = '';
    public $status = '';
    public $price = '';
    public $type = '';
    public $type2 = '';
    public $image = '';
    public $feed = '';
}
