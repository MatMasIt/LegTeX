<?php
/*
 * Main file, to be run as `php bot.php` indefinitely
 */
require("API.php");
require("process.php");
/**
 * Log to console
 * @param mixed $message
 * 
 * @return void
 */
function elog(mixed $message): void
{
    echo "[" . date("d/m/Y H:i:s") . "]" . (string) $message . "\n";
}
/**
 * Send error message to user given error result
 * @param mixed $update update object
 * @param mixed $text text to send
 * 
 * @return void
 */
function errorOut($update, $text): void
{
    elog("erroring out");
    API("sendMessage", ["chat_id" => $update["message"]["chat"]["id"], "text" => $text . "\n\n Guida: https://telegra.ph/Usare-LegTeX-12-04", "reply_to_message_id" => $update["message"]["id"], "parse_mode" => "HTML"]);
}

// main loop
do {
    $opts = []; // to get updates we have to send the last acknoledged update id + 1, or none
    $lastOffset = file_get_contents("lastUpdate");
    if ($lastOffset != "NONE") $opts["offset"] = $lastOffset + 1; // confirm all last updates
    //elog("Polling updates");
    $updates = API("getUpdates", $opts); // obtain updates
    if ($updates["ok"]) {
        foreach ($updates["result"] as $u) { // iterate over results
            if ($lastOffset != "NONE" && $u["update_id"] < $lastOffset) continue; // if an update is already acknoleged, skip
            $add = $u["message"]["from"]["username"] ? " (" . $u["message"]["from"]["username"] . ")" : ""; // add username in parentheses, if present to log
            elog("New update " . $u["id"] . " from " . $u["message"]["from"]["first_name"] . " " . $u["message"]["from"]["last_name"] . $add); // log update
            if ($u["message"]["chat"]["type"] != "private") { // if the chat is not private, skip
                elog("Update is not private chat, continuing");
                continue;
            }
            $o = processAndUploadToUrl($u["message"]["text"]); // process text and upload
            if (!$o["ok"]) errorOut($u, $o["text"]); // if failed error out
            else {
                // send document
                elog("Sending doc");
                API("sendDocument", ["chat_id" => $u["message"]["chat"]["id"], "document" => $o["file"], "parse_mode" => "HTML"]);
            }
            if ($lastOffset == "NONE" || $lastOffset < $u["update_id"]) $lastOffset = $u["update_id"];
            elog("Updating last update offset");
            file_put_contents("lastUpdate", $lastOffset);
        }
    }
    //elog("Sleeping");
    sleep(10);
} while (true);
