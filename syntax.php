<?php
/**
 * Plugin Airtable: Syncs Airtable Content to dokuWiki
 *
 * Syntax: <airtable>TYPE: xxx, TABLE: xxx, WHERE, .......</airtable> - will be replaced with airtable content
 *
 * @license    GPL 3 (https://www.gnu.org/licenses/quick-guide-gplv3.html)
 * @author     Cameron Ward <cameronward007@gmail.com>
 */
// must be run within DokuWiki
if(!defined('DOKU_INC')) die();

/**
 * Class InvalidAirtableString
 *
 * Handles the airtable query string exception and
 *
 */
class InvalidAirtableString extends Exception {
    public function errorMessage(): string {
        return $this->getMessage();
    }
}

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_airtable extends DokuWiki_Syntax_Plugin {

    /**
     * Get the type of syntax this plugin defines.
     *
     * @param
     * @return String <tt>'substition'</tt> (i.e. 'substitution').
     * @public
     * @static
     */
    function getType(): string {
        return 'substition';
    }

    /**
     * Define how this plugin is handled regarding paragraphs.
     *
     * <p>
     * This method is important for correct XHTML nesting. It returns
     * one of the following values:
     * </p>
     * <dl>
     * <dt>normal</dt><dd>The plugin can be used inside paragraphs.</dd>
     * <dt>block</dt><dd>Open paragraphs need to be closed before
     * plugin output.</dd>
     * <dt>stack</dt><dd>Special case: Plugin wraps other paragraphs.</dd>
     * </dl>
     * @param
     * @return String <tt>'block'</tt>.
     * @public
     * @static
     */
    function getPType(): string {
        return 'normal';
    }

    /**
     * Where to sort in?
     *
     * @param
     * @return Integer <tt>6</tt>.
     * @public
     * @static
     */
    function getSort(): int {
        return 1;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param $mode //The desired rendermode.
     * @return void
     * @public
     * @see render()
     */
    function connectTo($mode) {
        $this->Lexer->addEntryPattern('{{airtable>', $mode, 'plugin_airtable');
    }

    function postConnect() {
        $this->Lexer->addExitPattern('}}', 'plugin_airtable');
    }

    /**
     * Handler to prepare matched data for the rendering process.
     *
     * <p>
     * The <tt>$aState</tt> parameter gives the type of pattern
     * which triggered the call to this method:
     * </p>
     * <dl>
     * <dt>DOKU_LEXER_ENTER</dt>
     * <dd>a pattern set by <tt>addEntryPattern()</tt></dd>
     * <dt>DOKU_LEXER_MATCHED</dt>
     * <dd>a pattern set by <tt>addPattern()</tt></dd>
     * <dt>DOKU_LEXER_EXIT</dt>
     * <dd> a pattern set by <tt>addExitPattern()</tt></dd>
     * <dt>DOKU_LEXER_SPECIAL</dt>
     * <dd>a pattern set by <tt>addSpecialPattern()</tt></dd>
     * <dt>DOKU_LEXER_UNMATCHED</dt>
     * <dd>ordinary text encountered within the plugin's syntax mode
     * which doesn't match any pattern.</dd>
     * </dl>
     * @param $match   //String The text matched by the patterns.
     * @param $state   //Integer The lexer state for the match.
     * @param $pos     //Integer The character position of the matched text.
     * @param $handler //Object Reference to the Doku_Handler object.
     * @return array The current lexer state for the match.
     * @public
     * @see render()
     * @static
     */
    function handle($match, $state, $pos, $handler): array {
        switch($state) {
            case DOKU_LEXER_EXIT:
            case DOKU_LEXER_ENTER :
                /** @var array $data */
                $data = array();
                return $data;

            case DOKU_LEXER_SPECIAL:
            case DOKU_LEXER_MATCHED :
                break;

            case DOKU_LEXER_UNMATCHED :
                return array('airtable' => $match);

        }
        $data = array();
        return $data;
    }

    /**
     * Handle the actual output creation.
     *
     * <p>
     * The method checks for the given <tt>$aFormat</tt> and returns
     * <tt>FALSE</tt> when a format isn't supported. <tt>$aRenderer</tt>
     * contains a reference to the renderer object which is currently
     * handling the rendering. The contents of <tt>$aData</tt> is the
     * return value of the <tt>handle()</tt> method.
     * </p>
     * @param $mode      //String The output format to generate.
     * @param $renderer  Doku_Renderer A reference to the renderer object.
     * @param $data      //Array The data created by the <tt>handle()</tt>
     *                   method.
     * @return Boolean <tt>TRUE</tt> if rendered successfully, or
     *                   <tt>FALSE</tt> otherwise.
     * @public
     * @see          handle()
     * @noinspection PhpParameterNameChangedDuringInheritanceInspection
     */
    function render($mode, Doku_Renderer $renderer, $data): bool {
        //<airtable>Type: Image, Table: tblwWxohDeMeAAzdW, WHERE: {Ref #} = 19, image-size: small, alt-tag: marble-machine-x</airtable>

        if($mode != 'xhtml') return false;

        if(!empty($data['airtable'])) {

            define('BASE_ID', $this->getConf('Base_ID'));
            define('API_KEY', $this->getConf('API_Key'));
            define('MAX_RECORDS', $this->getConf('Max_Records'));
            try {
                $user_string  = $data['airtable'];
                $display_type = $this->getDisplayType($user_string); //check type is set correctly
                // MAIN PROGRAM:
                switch(true) { //parse string based on type set
                    case ($display_type == "img"):
                        $decoded_array = $this->parseImageString($user_string);
                        $response      = $this->sendRecordRequest($decoded_array);
                        $thumbnails    = $this->parseImageRequest($response);
                        if($thumbnails === false or $thumbnails === null) {
                            throw new InvalidAirtableString("Unknown 'parseImageRequest' error");
                        }
                        $renderer->doc .= $this->renderImage($decoded_array, $thumbnails, "max-width: 250px;");
                        return true;
                    case ($display_type == "record"): //if one record display as a template
                        $decoded_array               = $this->parseRecordString($user_string);
                        $response                    = $this->sendRecordRequest($decoded_array);
                        $decoded_array['thumbnails'] = $this->parseImageRequest($response);
                        $renderer->doc               .= $this->renderRecord($decoded_array, $response);
                        return true;
                    case ($display_type == "txt"):
                        $decoded_array = $this->parseTextString($user_string);
                        $response      = $this->sendRecordRequest($decoded_array);
                        $renderer->doc .= $this->renderText($decoded_array, $response);
                        return true;
                    case ($display_type = "tbl"):
                        $decoded_array               = $this->parseTableString($user_string);
                        $response                    = $this->sendTableRequest($decoded_array);
                        $decoded_array['thumbnails'] = $this->parseImageRequest($response);
                        if(count($response['records']) == 1) { //if query resulted in one record, render as a template
                            $renderer->doc .= $this->renderRecord($decoded_array, $response['records'][0]);
                        } else {
                            $renderer->doc .= $this->renderTable($decoded_array, $response);
                        }
                        return true;
                    default:
                        return false;
                }
            } catch(InvalidAirtableString $e) {
                $renderer->doc .= "<p style='color: red; font-weight: bold;'>Airtable Error: " . $e->getMessage() . "</p>";
                return false;
            }
        }
        return false;
    }

    /**
     * HTML for rendering a single image:
     *
     * @param        $data
     * @param        $images
     * @param string $image_styles
     * @return string
     */
    private function renderImage($data, $images, $image_styles = ""): string {
        if($data['position'] == "centre") {
            $position = "mediacentre";
        } elseif($data['position'] == 'right') {
            $position = "mediaright";
        } elseif($data['position'] == "left") {
            $position = "medialeft";
        } else {
            $position = '';
        }
        return '
        <div>
            <a href="' . $images['full']['url'] . '" target="_blank" rel="noopener">
                <img alt ="' . $data['alt-tag'] . '" src="' . $images[$data['image-size']]['url'] . '" style="' . $image_styles . '" class="airtable-image' . $position . '">
            </a>
        </div>';
    }

    /**
     * Private Method for rendering a single record.
     * Fields and field data appear on the left. If there is an image present,
     * it will appear to the top right of the text
     *
     * @param $decoded_array
     * @param $response
     * @return string
     * @throws InvalidAirtableString
     */
    private function renderRecord($decoded_array, $response): string {
        $fields = $decoded_array['fields'];
        $html   = '<div style="margin-bottom: 50px; clear: both">';
        if($decoded_array['thumbnails'] !== false) {
            $decoded_array['image-size'] = "large";
            $image_styles                = 'float: right; max-width: 350px; margin-left: 10px';
            $html                        .= $this->renderImage($decoded_array, $decoded_array['thumbnails'], $image_styles);
        }
        foreach($fields as $field) {
            if(!array_key_exists($field, $response['fields'])) { //if field is not present in array:
                throw new InvalidAirtableString("Invalid field name: " . htmlspecialchars($field));
            }
            if(is_array($response['fields'][$field])) {
                continue;
            }
            $html .= '
            <div>
                <h3>' . $field . '</h3>
                <p>' . $response['fields'][$field] . '</p>
            </div>';
        }
        $html .= '<div style="clear: both;"></div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Private method for rendering text.
     *
     * @param $decoded_array
     * @param $response
     * @return string
     * @throws InvalidAirtableString
     */
    private function renderText($decoded_array, $response): string {
        $fields = $decoded_array['fields'];
        $html   = '';
        foreach($fields as $field) {
            if(!array_key_exists($field, $response['fields'])) { //if field is not present in array:
                throw new InvalidAirtableString("Invalid field name: " . htmlspecialchars($field));
            }
            $html .= htmlspecialchars($response['fields'][$field]) . ' ';
        }
        $html = rtrim($html);
        return $html;
    }

    private function renderTable($decoded_array, $response): string {
        return "TABLE GOES HERE";
    }

    /**
     * Sets the required parameters for type: image
     * Also sets accepted values for specific parameters
     *
     * @param $user_string
     * @return array The decoded string with the parameter names stored as keys
     * @throws InvalidAirtableString
     */
    private function parseImageString($user_string): array {
        $image_parameter_types  = array("type" => true, "record-url" => true, 'table' => true, 'record-id' => true, "alt-tag" => "", "image-size" => "large", "position" => "block"); // accepted parameter names with default values or true if parameter is required.
        $image_parameter_values = array("image-size" => ["", "small", "large", "full"], "position" => ['', 'left', 'centre', 'right', 'block']); // can be empty (substitute default), small, large, full
        $image_query            = $this->decodeRecordURL($this->getParameters($user_string));
        return $this->checkParameters($image_query, $image_parameter_types, $image_parameter_values);
    }

    /**
     * Sets the required parameters for type: record
     * @param $user_string
     * @return array
     * @throws InvalidAirtableString
     */
    private function parseRecordString($user_string): array {
        $record_parameter_types  = array("type" => true, "record-url" => true, "table" => true, "fields" => true, "record-id" => true, "alt-tag" => "");
        $record_parameter_values = array();
        $record_query            = $this->decodeRecordURL($this->getParameters($user_string));
        return $this->checkParameters($record_query, $record_parameter_types, $record_parameter_values);
    }

    /**
     * Sets the required parameters for type: text
     *
     * @param $user_string
     * @return array
     * @throws InvalidAirtableString
     */
    private function parseTextString($user_string): array {
        $text_parameter_types  = array("type" => true, "table" => true, "fields" => true, "record-id" => true, "record-url" => true);
        $text_parameter_values = array();
        $text_query            = $this->decodeRecordURL($this->getParameters($user_string));
        return $this->checkParameters($text_query, $text_parameter_types, $text_parameter_values);
    }

    /**
     * Sets the required parameters for type: table
     *
     * @param $user_string
     * @return array
     * @throws InvalidAirtableString
     */
    private function parseTableString($user_string): array {
        $table_parameter_types  = array("type" => true, "table" => true, "fields" => true, "record-url" => true, "where" => "", "order-by" => "", "order" => "asc", "max-records" => "");
        $table_parameter_values = array("order" => ["asc", "desc"]);
        $table_query            = $this->decodeRecordURL($this->getParameters($user_string));
        return $this->checkParameters($table_query, $table_parameter_types, $table_parameter_values);
    }

    /**
     * parse query string and return the type
     *
     * @param $user_string //data between airtable tags e.g.: <airtable>user_string</airtable>
     * @return string //the display type (image, table, text)
     * @throws InvalidAirtableString
     */
    private function getDisplayType($user_string): string {
        $type = substr($user_string, 0, strpos($user_string, " | "));
        if($type == "") {
            throw new InvalidAirtableString("Missing Type Parameter / Not Enough Parameters");
        }
        $decoded_string = explode("type: ", strtolower($type))[1];
        $decoded_type   = str_replace('"', '', $decoded_string);
        if($decoded_type == null) {
            throw new InvalidAirtableString("Missing Type Parameter");
        }
        $decoded_type   = strtolower($decoded_type);
        $accepted_types = array("img", "image", "picture", "text", "txt", "table", "tbl", "record");
        if(!array_search($decoded_type, $accepted_types)) {
            throw new InvalidAirtableString(
                "Invalid Type Parameter: " . htmlspecialchars($decoded_type) . "
            <br>Accepted Types: " . implode(" | ", $accepted_types)
            );
        }
        if($decoded_type == "img" || $decoded_type == "image" || $decoded_type == "picture") {
            $decoded_type = "img";
        }
        if($decoded_type == "text") {
            $decoded_type = "txt";
        }
        if($decoded_type == "table") {
            $decoded_type = "tbl";
        }
        return $decoded_type;
    }

    /**
     * Splits the query string into an associative array of Type => Value pairs
     *
     * @param $user_string string The user's airtable query
     * @return array
     */
    private function getParameters(string $user_string): array {
        $query        = array();
        $string_array = explode(' | ', $user_string);
        foreach($string_array as $item) {
            $parameter                        = explode(": ", $item); //creates key value pairs for parameters e.g. [type] = "image"
            $query[strtolower($parameter[0])] = str_replace('"', '', $parameter[1]); //removes quotes
        }
        if(array_key_exists("fields", $query)) { // separate field names into an array if it exists
            $fields          = array_map("trim", explode(",", $query['fields'])); //todo: url encode fields here?
            $query['fields'] = $fields;
        }
        return $query;
    }

    /**
     * Extracts the table, view and record information from record-url
     *
     * @param $query
     * @return mixed
     * @throws InvalidAirtableString
     */
    private function decodeRecordURL($query) {
        if(array_key_exists("record-url", $query)) {
            //// "tbl\w+|viw\w+|rec\w+/ig" One line preg match?
            preg_match("/tbl\w+/i", $query["record-url"], $table); //extract table, view, record from url
            preg_match("/viw\w+/i", $query["record-url"], $view);
            preg_match("/rec\w+/i", $query["record-url"], $record_id);

            $query['table']     = urlencode($table[0]); //url encode each part
            $query['view']      = urlencode($view[0]);
            $query['record-id'] = urlencode($record_id[0]);
            return $query;
        } else {
            throw new InvalidAirtableString("Missing record-url parameter");
        }
    }

    /**
     * Checks query parameters to make sure:
     *      Required parameters are present
     *      Missing parameters are substituted with default params
     *      Parameter values match expected values
     *
     * @param $query_array         array
     * @param $required_parameters array
     * @param $parameter_values    array
     * @return array // query array with added default parameters
     * @throws InvalidAirtableString
     */
    private function checkParameters(array &$query_array, array $required_parameters, array $parameter_values): array {
        foreach($required_parameters as $key => $value) {
            if(!array_key_exists($key, $query_array)) { // if parameter is missing:
                if($value === true) { // check if parameter is required
                    throw new InvalidAirtableString("Missing Parameter: " . $key);
                }
                $query_array[$key] = $value; // substitute default
            }
            if(($query_array[$key] == null || $query_array[$key] === "") && $value === true) { //if parameter is required but value is not present
                throw new InvalidAirtableString("Missing Parameter Value for: '" . $key . "'.");
            }
            if(array_key_exists($key, $parameter_values)) { //check accepted parameter_values array
                if(!in_array($query_array[$key], $parameter_values[$key])) { //if parameter value is not accepted:
                    $message = "Invalid Parameter Value: '" . htmlspecialchars($query_array[$key]) . "' for Key: '" . $key . "'.
                    <br>Possible values: " . implode(" | ", $parameter_values[$key]);
                    if(in_array("", $parameter_values[$key])) {
                        $message .= " or ''";
                    }
                    throw new InvalidAirtableString($message);
                }
            }
        }
        return $query_array;
    }

    /**
     * Recursive method to find an array (needle) within the JSON response (haystack)
     *
     * @param        $haystack
     * @param string $needle
     * @return false|array
     */
    private function parseImageRequest($haystack, $needle = "thumbnails") {
        foreach($haystack as $key) {
            if(is_array($key)) {
                if(array_key_exists($needle, $key)) {
                    return $key[$needle];
                }
                $search = $this->parseImageRequest($key, $needle);
                if($search === false) {
                    continue;
                } else {
                    return $search; // image attachment found
                }
            }
        }
        return false;
    }

    /**
     * Method to encode a record request
     *
     * @param $data
     * @return false|string //JSON String
     * @throws InvalidAirtableString
     */
    private function sendRecordRequest($data) {
        $request = $data['table'] . '/' . urlencode($data['record-id']);
        return $this->sendRequest($request);
    }

    /**
     * Method to encode a table request
     *
     * @param $data
     * @return false|string
     * @throws InvalidAirtableString
     */
    private function sendTableRequest($data) {
        $request = $data['table'] . '?';
        //Add each field to the request string
        foreach($data['fields'] as $index => $field) {
            if($index >= 1) {
                $request .= '&' . urlencode('fields[]') . '=' . urlencode($field);
            } else {
                $request .= urlencode('fields[]') . '=' . urlencode($field); //don't add a '&' for the first field
            }
        }

        //add filter:
        if(key_exists('where', $data)) {
            $request .= '&filterByFormula=' . urlencode($data['where']);
        }

        //Set max records:
        if(key_exists('max-records', $data)) {
            if((int) $data['max-records'] <= MAX_RECORDS) {
                $max_records = $data['max-records'];
            } else {
                $max_records = MAX_RECORDS;
            }
        } else {
            $max_records = MAX_RECORDS;
        }
        $request .= '&maxRecords=' . $max_records;

        //set order by which field and order direction:
        if(key_exists('order-by', $data)) {
            $request .= '&' . urlencode('sort[0][field]') . '=' . urlencode($data['order-by']);
        }
        if(key_exists('order', $data)) {
            $order = $data['order'];
        } else {
            $order = "asc";
        }

        $request .= '&' . urlencode('sort[0][direction]') . '=' . urlencode($order);

        return $this->sendRequest($request);
    }

    /**
     * Method to call the airtable API
     *
     * @param $request
     * @return false|string
     * @throws InvalidAirtableString
     */
    private function sendRequest($request) {
        $url  = 'https://api.airtable.com/v0/' . BASE_ID . '/' . $request;
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $headers = array(
            'Authorization: Bearer ' . API_KEY
        );

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        //TODO: remove once in production:
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);//

        $response = json_decode(curl_exec($curl), true); //decode JSON to associative array

        if(curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200) {
            $message = '';
            if(key_exists("error", $response)) {
                $message = $response['error']['message'];
            } else {
                $message = "Unknown API response error";
            }
            throw new InvalidAirtableString($message);
        }
        curl_close($curl);
        return $response;
    }
}