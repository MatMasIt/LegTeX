<?php
error_reporting(E_ERROR);

/**
 * Get italian date 
 * @param mixed $UNIX unix timestamp
 * 
 * @return string the italian date
 */
function ITDate($UNIX): string
{
    $mesi = array(
        1 => 'gennaio', 'Febbraio', 'Marzo', 'Aprile',
        'Maggio', 'Giugno', 'Luglio', 'Agosto',
        'Settembre', 'Ottobre', 'Novembre', 'Dicembre'
    );

    $giorni = array(
        'Domenica', 'Lunedì', 'Martedì', 'Mercoledì',
        'Giovedì', 'Venerdì', 'Sabato'
    );

    list($sett, $giorno, $mese, $anno) = explode('-', date('w-d-n-Y', $UNIX));

    //return  $giorni[$sett].' '.$giorno.' '.$mesi[$mese].' '.$anno;
    return  'il ' . $giorno . ' ' . $mesi[$mese] . ', anno ' . $anno;
}
/**
 * Sanitize latex and enhance
 * @param mixed $text the input
 * 
 * @return string the sanitized and enchanced text
 */
function enchanceSanitizeLatex($text): string
{
    $text = str_replace("\\", "\\\\", $text);
    $text = str_replace("{", "\\{", $text);
    $text = str_replace("}", "\\}", $text);
    $text = str_replace("<i>", "\\textit{", $text);
    $text = str_replace("<b>", "\\textbb{", $text);
    $text = str_replace("</i>", "}", $text);
    $text = str_replace("</b>", "}", $text);
    $text = str_replace(" , ", ", ", $text);
    $text = str_replace("<indenta>", "\\hspace{\parindent}", $text);
    return $text;
}
/**
 * Map "::key:: value" syntax
 * @param mixed $textcode text to map
 * 
 * @return array map
 */
function synMapper($textcode): array
{

    elog("Mapping document syntax");
    $s = explode("::", $textcode);
    $map = [];
    if (count($s) % 2 == 0) {
        elog("Syntax is not valid");
        return null;
    }
    for ($i = 1; $i < count($s); $i += 2) { // skipping first one
        $map[$s[$i]] = enchanceSanitizeLatex(trim($s[$i + 1]));
    }
    elog("Mapping complete");
    return $map;
}
/**
 * Build tex from textcode
 * @param mixed $textcode textcode
 * 
 * @return array result
 */
function build($textcode)
{

    $rn = bin2hex(random_bytes(16)); // create job
    elog("Job id is " . $rn);
    $map = synMapper($textcode);
    if (!$map) return ["ok" => false, "text" => "Sintassi errata"];
    elog("Loading model");
    $model = file_get_contents("model.tex");
    if (!$map["leg"]) {
        elog("Legislature not specified");
        return ["ok" => false, "text" => "La legislatura non è stata specificata, usare il parametro ::leg::\n Per esempio, \n::leg::XV"];
    }
    // set legislature
    $model = str_replace("{{LEG}}", $map["leg"], $model);
    $artAllowed = true;
    // set headings depending on document type
    switch ($map["tipo"]) {
        case "ddl":
            elog("Ddl document");
            $map["tipo"] = "Disegno di Legge";
            switch ($map["inziativa"]) {
                case "gov":
                    elog("Presented by government");
                    $map["iniziativa"] = "PRESENTATO DAL GOVERNO";
                    break;
                case "dep":
                    elog("Presented by deputies");
                    return ["ok" => false, "text" => "Un disegno di legge può solo essere creato dal governo, non dai deputati.\n Stai pensando ad una proposta di legge (pdl)?"];
                    break;
                default:
                    elog("Custom or empty initiative");
                    if (empty($map["iniziativa"])) $map["iniziativa"] = "PRESENTATO DAL GOVERNO";
                    break;
            }
            break;
        case "moz":
            elog("Motion");
            $map["tipo"] = "Mozione";
            if (empty($map["iniziativa"])) {
                elog("Motions must have initiative specified");
                return ["ok" => false, "text" => "Per le mozioni è necessario specificare una iniziativa\n ::iniziativa:: gov\n per il governo o \n ::iniziativa:: dep\n per i deputati\n oppure ::iniziativa:: DI INIZIATIVA TESTO PERSONALIZATO"];
            }
            switch ($map["iniziativa"]) {
                case "gov":
                    elog("Presented by government");
                    $map["iniziativa"] = "PRESENTATA DEL GOVERNO";
                    break;
                case "dep":
                    elog("Presented by deputies");
                    $map["iniziativa"] = "D'INIZIATIVA DEI DEPUTATI";
                    break;
                default:
                    elog("Custom initiative");
                    $map["iniziativa"] = strtoupper(trim($map["iniziativa"]));
                    break;
            }
            $artAllowed = false;
            if ($artAllowed)
                elog("Articles are allowed");
            else
                elog("Articles not allowed");
            break;
        default:
        case "pdl":
            elog("pdl document");
            $map["tipo"] = "Proposta di Legge";
            if ($map["inziativa"] == "gov") {
                elog("Government cannot make pdl");
                return ["ok" => false, "text" => "Una proposta di legge può solo essere creato dai deputati, non dal governo.\n Stai pensando ad un disegno di legge (ddl)?"];
            }
            $map["iniziativa"] = "D'INIZIATIVA  DEI DEPUTATI";
            break;
    }

    // check needed fields
    if (empty($map["mozione"]) && $map["tipo"] == "Mozione") {

        elog("Motion missing");
        return ["ok" => false, "text" => "Specificare l'inizio della mozione con \n ::mozione::"];
    }
    if (empty($map["relatori"])) {

        elog("Relators missing");
        return ["ok" => false, "text" => "Deve essere specificato almeno un relatore\n ::relatori::"];
    }
    if (empty($map["titolo"])) {

        elog("Title missing");
        return ["ok" => false, "text" => "Deve essere specificato un titolo\n ::titolo::"];
    }
    if (empty($map["data"]) || ($time = strtotime($map["data"])) === false) {

        elog("Date invalid or missing");
        return ["ok" => false, "text" => "Deve essere specificata una data valida\n ::DATA::"];
    }
    if (empty($map["intro"])) {

        elog("Intro missing");
        return ["ok" => false, "text" => "Deve essere specificata una introduzione\n ::intro::"];
    }

    // create relators list, fixing spacing issues
    $relatori = "";
    $i = 0;
    foreach (explode(",", $map["relatori"]) as $r) {
        if ($i != 0) $relatori .= ", ";
        $relatori .= trim(strtoupper($r));
        $i++;
    }

    // set all the needed heading data
    $model = str_replace("{{TIPO}}", $map["tipo"], $model);
    $model = str_replace("{{INIZ}}", $map["iniziativa"], $model);
    $model = str_replace("{{RELATORI}}", $relatori, $model);
    $model = str_replace("{{TITOLO}}", $map["titolo"], $model);
    $model = str_replace("{{DATA}}", ITDate($time), $model);
    $model = str_replace("{{NLAW}}", $map["nlegge"] ?: "1", $model);

    // add newlines after "." in intro
    $map["intro"] = str_replace(".", ".\\newline ", trim($map["intro"]));
    $model = str_replace("{{INTRO}}", $map["intro"], $model);
    $articleMap = [];
    // map articles
    elog("Mapping articles");
    foreach ($map as $key => $val) {
        $key = trim(strtolower($key));
        if (str_starts_with($key, "art ")) {
            if (!$artAllowed) { // oops, articles not allowed
                elog("Articles were allowed ");
                return ["ok" => false, "text" => "\"" . $map["tipo"] . "\" non può contenere articoli"];
            }
            $ae = explode(" ", $key, 2); // the key may be like "art 1"
            $articleMap[trim((string)$ae[1])] = trim($val); // set key in map
        }
    }
    $artpage = "";
    if (count($articleMap)) {
        elog("Sorting articles");
        ksort($articleMap);
        $atext = "";
        elog("Fetching article templates");
        // load templates
        $artpage = file_get_contents("artpage.tex");
        $articleModel  = file_get_contents("art.tex");
        $artpage = str_replace("{{TIPO}}", $map["tipo"], $artpage);
        foreach ($articleMap as $artN => $content) {
            // replace article data
            elog("Building article " . $artN . "--");
            $amodelTemp = str_replace("{{ARTN}}", $artN, $articleModel);
            $content = str_replace(".", ".\\newline ", trim($content));
            $amodelTemp = str_replace("{{ARTTEXT}}", $content, $amodelTemp);
            $atext .= $amodelTemp . "\n\n";
        }
        $artpage = str_replace("{{ARTS}}", $atext, $artpage);
    } else if ($map["tipo"] == "Mozione") {
        // special motion heading
        $artpage = <<<'EOD'
        \begin{multicols}{2}
        \vspace*{2cm}
        \begin{center}
          \large{\textsc{ATTI D'INDIRIZZO}}\entry{1pt}
          \hspace{\fill}\makebox[5mm]{\hrulefill}\hspace{\fill}
          \entry{1cm}
        \end{center}
        
        \begin{center}
          \setlength{\columnseprule}{0.1mm}
          \textsc{Mozione}\\
        \end{center}
        \par
        EOD;
        $artpage = trim($artpage). " ";
        $artpage .= $map["mozione"];
        $artpage.="\n\\end{multicols}";
    }
    $model = str_replace("{{ARTPAGE}}", $artpage, $model);
    elog("Tex ready!");
    return ["ok" => true, "text" => $model, "rng" => "out/" . $rn . ".tex", "titolo" => $map["titolo"]];
}

/** Checks wether
 * @param array $arr
 * 
 * @return bool
 */
function isAssoc(array $arr): bool
{
    if (array() === $arr) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}
/** Recusrively extract text from telegra.ph tree
 * @param mixed $node top node
 * 
 * @return string the text
 */
function recurseText($node): string
{
    $res = "";
    if (is_array($node) && !isAssoc($node)) {
        foreach ($node as $enode) {
            $res .= recurseText($enode);
        }
        return $res;
    }
    if (is_string($node)) return $node;
    if ($node["tag"] == "p") $res .= "\n";
    if ($node["tag"] == "em" || $node["tag"] == "i") $res .= "<i>";
    elseif ($node["tag"] == "b" || $node["tag"][0] == "h") $res .= "<b>";
    if (isset($node["children"])) {
        foreach ($node["children"] as $cnode) {
            $res .= recurseText($cnode);
        }
    }
    if ($node["tag"] == "em") $res .= "</i>";
    elseif ($node["tag"] == "b" || $node["tag"][0] == "h") $res .= "</b>";
    return $res;
}

/**
 * Get telegraph url
 * @param mixed $url telegraph.url
 * 
 * @return array result
 */
function getTelegraphContent($url)
{
    $url = str_replace("http://", "https://", $url);
    if (!str_starts_with($url, "https://telegra.ph/")) {
        elog("Url not in allowed namespace");
        return ["ok" => false, "text" => "Inviare un url telegra.ph"];
    }
    $pageName = explode("/", $url, 4); // [https:]/[]/[telegra.ph]/[...]
    $json = file_get_contents("https://api.telegra.ph/getPage/" . $pageName[count($pageName) - 1] . "?return_content=true");
    $data = json_decode($json, true);
    elog("Started fetching telegraph data");
    if ($data == null || !$data["ok"]) {
        elog("Data fetching failed");
        return ["ok" => false, "text" => "Impossibile recuperare i dati"];
    }

    elog("Extracting text");
    $text = recurseText($data["result"]["content"]);
    elog("Text extracted");
    return ["ok" => true, "text" => $text];
}
/** Sanitize filename
 * @param mixed $titolo filename
 * 
 * @return string safe filename
 */
function sanitizefn($titolo)
{
    $titolo = strtolower($titolo);
    $final = "";
    foreach (str_split($titolo) as $c) {
        if (!in_array($c, str_split("abcdefghijklmnopqrstuvwxyz1234567890"))) $final .= "_";
        else $final .= $c;
    }
    return $final;
}
/** Process telegraph url and obtain pdf url (abstracted)
 * @param mixed $telegraphUrl telegraph url
 * 
 * @return array result
 */
function processAndUploadToUrl($telegraphUrl)
{
    elog("Procesing " . $telegraphUrl);
    $out = getTelegraphContent($telegraphUrl);
    if (!$out["ok"]) return $out;
    elog("Started building");
    $q = build($out["text"]);
    if (!$q["ok"]) return $q;
    elog("Saving tex");
    file_put_contents($q["rng"], $q["text"]);
    $out = [];
    $retval = 0;
    elog("Building pdf");
    exec("pdflatex -interaction=nonstopmode -output-directory out " . $q["rng"] . "", $out, $retval);
    $out = implode("\n", $out);
    if ($retval != 0) {
        echo $out;
        elog("Error in building pdf");
        $all = str_replace(".tex", ".*", $q["rng"]);
        foreach (glob($all) as $f) unlink($f);
        return ["ok" => false, "text" => "PDFLATEX error:\n Tell @matmasak"];
    }
    $pdffile = str_replace(".tex", ".pdf", $q["rng"]);
    $out = [];
    $retval = 0;
    elog("Uploading pdf");
    exec("curl --upload-file " . $pdffile . " https://transfer.sh/" . sanitizefn($q["titolo"]) . ".pdf", $out, $retval);
    if ($retval != 0) {
        elog("Error in uploading pdf");
        $all = str_replace(".tex", ".*", $q["rng"]);
        foreach (glob($all) as $f) unlink($f);
        return ["ok" => false, "text" => "CURL error:\n" . $out];
    }
    $all = str_replace(".tex", ".*", $q["rng"]);
    foreach (glob($all) as $f) unlink($f);
    $out = implode("\n", $out);
    elog("File uploaded to " . trim($out));
    return ["ok" => true, "file" => trim($out)];
}
