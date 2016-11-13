<?php
/**
 * process_party_feeds.php
 *
 * Eventbrite NYC parties - calls Eventbrite search API.
 *   https://www.eventbriteapi.com
 *   https://www.eventbrite.com/d/ny--new-york/parties/?crt=regular&sort=date
 *
 * NightOut New York - calls NighOut search API .
 *   https://nightout.com/api/search.json?timing%5B%5D=week&city=2&page=1
 *
 * NOTE: Make sure the client email address (fb-video-dashboard@nyt-newsroom-dashboards.iam.gserviceaccount.com)
 * has edit permission on the sheet.  Otherwise will need to update code to impersonate someone.
 *
 * ----
 *
 * https://www.timeout.com/newyork/bars/bar-openings-and-events-in-nyc
 * https://www.timeout.com/newyork/nightlife/best-parties-in-nyc-this-week
 * https://nightout.com/ny/new-york
 * http://www.nycgo.com/things-to-do/events-in-nyc/nightlife-calendar
 * http://www.nyc.com/concert_tickets/
 * http://www.villagevoice.com/calendar
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
curl_setopt($ch,CURLOPT_HTTPGET, TRUE);
curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);


$no_events = get_nightout_events($ch);
$eb_events = get_evenbrite_events($ch, $eventbrite_oauth_token);

$events = array_merge($no_events, $eb_events);

// TODO: order events by date

// Get the Google Sheets Service.
$service = get_service();

// Clear data in spreadsheet.
print "Clearing sheet...\n\n";
clear_spreadsheet($service, $spreadsheet_id, $sheet_id);

$rows = array();
$rows[] = get_first_row();

$row_num = 0;
// Write first two rows.
write_rows_to_sheet($service, $spreadsheet_id, $sheet_id, $rows, $row_num);
$row_num++;

$rows = array();
foreach($events as $event) {
    $rows[] = get_event_row($event);
    print  '  ' . $event->name . "\n";
}
write_rows_to_sheet($service, $spreadsheet_id, $sheet_id, $rows, $row_num);


$end_time = microtime(true);
$diff_time = $end_time - $start_time;
printf("Took %2.1fs to run and used %s of memory.\n", $diff_time, bytes_to_nice_string(memory_get_usage()));

exit(0);


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

        curl_setopt($ch,CURLOPT_URL, $nightout_url . '&page=' . $page_number);
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

        curl_setopt($ch,CURLOPT_URL, $eventbrite_url . '&page=' . $page_number);
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
 * Get first row.
 */
function get_first_row()
{
    global $color;
    $row = new Google_Service_Sheets_RowData();
    // get column headers
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
    return ($row);
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
