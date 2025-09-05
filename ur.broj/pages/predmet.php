<?php

/**
 * Plaćena licenca
 * (c) 2025 Tomislav Galić <tomislav@8core.hr>
 * Suradnik: Marko Šimunović <marko@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
/**
 *	\file       seup/predmet.php
 *	\ingroup    seup
 *	\brief      Individual predmet view with documents and details
 */

// Učitaj Dolibarr okruženje
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';

// Local classes
require_once __DIR__ . '/../class/predmet_helper.class.php';
require_once __DIR__ . '/../class/request_handler.class.php';
require_once __DIR__ . '/../class/omat_generator.class.php';

// Load translation files
$langs->loadLangs(array("seup@seup"));

// Get predmet ID
$caseId = GETPOST('id', 'int');
if (!$caseId) {
    header('Location: predmeti.php');
    exit;
}

// Security check
$socid = GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
    $socid = $user->socid;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = GETPOST('action', 'alpha');
    
    // Handle document upload
    if ($action === 'upload_document') {
        Request_Handler::handleUploadDocument($db, '', $langs, $conf, $user);
        // Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $caseId);
        exit;
    }
    
    // Handle AJAX requests
    if ($action === 'get_akti_for_predmet') {
        Request_Handler::handleGetAktiForPredmet($db, $conf);
        exit;
    }
    
    if ($action === 'delete_akt') {
        Request_Handler::handleDeleteAkt($db, $conf, $user);
        exit;
    }
    
    if ($action === 'delete_prilog') {
        Request_Handler::handleDeletePrilog($db, $conf, $user);
        exit;
    }
    
    if ($action === 'move_to_akt') {
        Request_Handler::handleMoveToAkt($db, $conf, $user);
        exit;
    }
    
    // Handle document deletion
    if ($action === 'delete_document') {
        header('Content-Type: application/json');
        ob_end_clean();
        
        $filename = GETPOST('filename', 'alpha');
        $filepath = GETPOST('filepath', 'alpha');
        
        if (empty($filename) || empty($filepath)) {
            echo json_encode(['success' => false, 'error' => 'Missing filename or filepath']);
            exit;
        }
        
        try {
            // Delete from filesystem
            $full_path = DOL_DATA_ROOT . '/ecm/' . rtrim($filepath, '/') . '/' . $filename;
            if (file_exists($full_path)) {
                unlink($full_path);
            }
            
            // Delete from ECM database
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "ecm_files 
                    WHERE filepath = '" . $db->escape(rtrim($filepath, '/')) . "'
                    AND filename = '" . $db->escape($filename) . "'
                    AND entity = " . $conf->entity;
            
            if ($db->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Dokument je uspješno obrisan']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $db->lasterror()]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Handle omat generation
    if ($action === 'generate_omot') {
        header('Content-Type: application/json');
        ob_end_clean();
        
        $omot_generator = new Omat_Generator($db, $conf, $user, $langs);
        $result = $omot_generator->generateOmat($caseId, true);
        
        echo json_encode($result);
        exit;
    }
    
    // Handle omot preview
    if ($action === 'preview_omot') {
        header('Content-Type: application/json');
        ob_end_clean();
        
        $omot_generator = new Omat_Generator($db, $conf, $user, $langs);
        $result = $omot_generator->generatePreview($caseId);
        
        echo json_encode($result);
        exit;
    }
}

// Fetch predmet details
$sql = "SELECT 
            p.ID_predmeta,
            p.klasa_br,
            p.sadrzaj,
            p.dosje_broj,
            p.godina,
            p.predmet_rbr,
            p.naziv_predmeta,
            DATE_FORMAT(p.tstamp_created, '%d.%m.%Y') as datum_otvaranja,
            u.name_ustanova,
            u.code_ustanova,
            k.ime_prezime,
            k.rbr as korisnik_rbr,
            k.naziv as radno_mjesto,
            ko.opis_klasifikacijske_oznake,
            ko.vrijeme_cuvanja
        FROM " . MAIN_DB_PREFIX . "a_predmet p
        LEFT JOIN " . MAIN_DB_PREFIX . "a_oznaka_ustanove u ON p.ID_ustanove = u.ID_ustanove
        LEFT JOIN " . MAIN_DB_PREFIX . "a_interna_oznaka_korisnika k ON p.ID_interna_oznaka_korisnika = k.ID
        LEFT JOIN " . MAIN_DB_PREFIX . "a_klasifikacijska_oznaka ko ON p.ID_klasifikacijske_oznake = ko.ID_klasifikacijske_oznake
        WHERE p.ID_predmeta = " . (int)$caseId;

$resql = $db->query($sql);
$predmet = null;
if ($resql && $obj = $db->fetch_object($resql)) {
    $predmet = $obj;
    $predmet->klasa_format = $obj->klasa_br . '-' . $obj->sadrzaj . '/' . 
                            $obj->godina . '-' . $obj->dosje_broj . '/' . 
                            $obj->predmet_rbr;
}

if (!$predmet) {
    header('Location: predmeti.php');
    exit;
}

// Fetch uploaded documents
$documentTableHTML = '';
Predmet_helper::fetchUploadedDocuments($db, $conf, $documentTableHTML, $langs, $caseId);

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", "Predmet: " . $predmet->klasa_format, '', '', 0, 0, '', '', '', 'mod-seup page-predmet');

// Modern design assets
print '<meta name="viewport" content="width=device-width, initial-scale=1">';
print '<link rel="preconnect" href="https://fonts.googleapis.com">';
print '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
print '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">';
print '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
print '<link href="/custom/seup/css/seup-modern.css" rel="stylesheet">';
print '<link href="/custom/seup/css/predmet.css" rel="stylesheet">';
print '<link href="/custom/seup/css/prilozi.css" rel="stylesheet">';
print '<link href="/custom/seup/css/akti-prilozi.css" rel="stylesheet">';

// Main container
print '<div class="seup-predmet-container">';

// Case details header
print '<div class="seup-case-details">';
print '<div class="seup-case-header">';
print '<div class="seup-case-icon"><i class="fas fa-folder-open"></i></div>';
print '<div class="seup-case-title">';
print '<h4>' . htmlspecialchars($predmet->naziv_predmeta) . '</h4>';
print '<div class="seup-case-klasa">' . $predmet->klasa_format . '</div>';
print '</div>';
print '</div>';

print '<div class="seup-case-grid">';

print '<div class="seup-case-field">';
print '<div class="seup-case-field-label"><i class="fas fa-building"></i>Ustanova</div>';
print '<div class="seup-case-field-value">' . ($predmet->name_ustanova ?: 'N/A') . '</div>';
print '</div>';

print '<div class="seup-case-field">';
print '<div class="seup-case-field-label"><i class="fas fa-user"></i>Zaposlenik</div>';
print '<div class="seup-case-field-value">' . ($predmet->ime_prezime ?: 'N/A') . '</div>';
print '</div>';

print '<div class="seup-case-field">';
print '<div class="seup-case-field-label"><i class="fas fa-calendar"></i>Datum Otvaranja</div>';
print '<div class="seup-case-field-value">' . $predmet->datum_otvaranja . '</div>';
print '</div>';

print '<div class="seup-case-field">';
print '<div class="seup-case-field-label"><i class="fas fa-clock"></i>Vrijeme Čuvanja</div>';
$vrijeme_text = ($predmet->vrijeme_cuvanja == 0) ? 'Trajno' : $predmet->vrijeme_cuvanja . ' godina';
print '<div class="seup-case-field-value">' . $vrijeme_text . '</div>';
print '</div>';

print '</div>'; // seup-case-grid
print '</div>'; // seup-case-details

// Tab navigation
print '<div class="seup-tabs">';
print '<button class="seup-tab active" data-tab="prilozi"><i class="fas fa-paperclip"></i>Prilozi</button>';
print '<button class="seup-tab" data-tab="prepregled"><i class="fas fa-eye"></i>Prepregled</button>';
print '<button class="seup-tab" data-tab="statistike"><i class="fas fa-chart-bar"></i>Statistike</button>';
print '</div>';

// Tab content
print '<div class="seup-tab-content">';

// Tab 1: Prilozi (Documents)
print '<div class="seup-tab-pane active" id="prilozi">';

// Upload section
print '<div class="seup-upload-section">';
print '<i class="fas fa-cloud-upload-alt seup-upload-icon"></i>';
print '<p class="seup-upload-text">Dodajte novi dokument u predmet</p>';
print '<button type="button" class="seup-btn seup-btn-primary" id="openUploadModalBtn">';
print '<i class="fas fa-plus me-2"></i>Dodaj dokument';
print '</button>';
print '</div>';

// Documents list
print '<div class="seup-documents-header">';
print '<h5 class="seup-documents-title"><i class="fas fa-paperclip"></i>Dokumenti u Prilozima</h5>';
print '</div>';

print $documentTableHTML;

print '</div>'; // Tab 1

// Tab 2: Prepregled
print '<div class="seup-tab-pane" id="prepregled">';
print '<div class="seup-preview-container">';
print '<i class="fas fa-file-alt seup-preview-icon"></i>';
print '<h4 class="seup-preview-title">Omot Spisa</h4>';
print '<p class="seup-preview-description">Generirajte ili pregledajte A3 omat spisa s osnovnim informacijama i popisom privitaka</p>';

print '<div class="seup-action-buttons">';
print '<button type="button" class="seup-btn seup-btn-primary" id="generateOmotBtn">';
print '<i class="fas fa-file-pdf me-2"></i>Kreiraj PDF';
print '</button>';
print '<button type="button" class="seup-btn seup-btn-secondary" id="printOmotBtn">';
print '<i class="fas fa-print me-2"></i>Ispis';
print '</button>';
print '<button type="button" class="seup-btn seup-btn-success" id="previewOmotBtn">';
print '<i class="fas fa-eye me-2"></i>Prepregled';
print '</button>';
print '</div>';

print '</div>';
print '</div>'; // Tab 2

// Tab 3: Statistike
print '<div class="seup-tab-pane" id="statistike">';
print '<div class="seup-stats-container">';
print '<h5><i class="fas fa-chart-bar"></i>Statistike Predmeta</h5>';
print '<div class="seup-stats-grid">';

// Count documents
$doc_count = 0;
$sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "ecm_files 
        WHERE filepath LIKE '%predmet_" . $caseId . "%' OR filepath LIKE '%/" . $caseId . "/%'
        AND entity = " . $conf->entity;
$resql = $db->query($sql);
if ($resql && $obj = $db->fetch_object($resql)) {
    $doc_count = $obj->count;
}

print '<div class="seup-stat-card">';
print '<i class="fas fa-file-alt seup-stat-icon"></i>';
print '<div class="seup-stat-number">' . $doc_count . '</div>';
print '<div class="seup-stat-label">Dokumenata</div>';
print '</div>';

print '<div class="seup-stat-card">';
print '<i class="fas fa-calendar seup-stat-icon"></i>';
print '<div class="seup-stat-number">' . $predmet->datum_otvaranja . '</div>';
print '<div class="seup-stat-label">Datum Otvaranja</div>';
print '</div>';

print '<div class="seup-stat-card">';
print '<i class="fas fa-clock seup-stat-icon"></i>';
print '<div class="seup-stat-number">' . $vrijeme_text . '</div>';
print '<div class="seup-stat-label">Vrijeme Čuvanja</div>';
print '</div>';

print '<div class="seup-stat-card">';
print '<i class="fas fa-user seup-stat-icon"></i>';
print '<div class="seup-stat-number">' . ($predmet->korisnik_rbr ?: 'N/A') . '</div>';
print '<div class="seup-stat-label">Oznaka Korisnika</div>';
print '</div>';

print '</div>'; // seup-stats-grid
print '</div>'; // seup-stats-container
print '</div>'; // Tab 3

print '</div>'; // seup-tab-content
print '</div>'; // seup-predmet-container

// Omot Preview Modal
print '<div class="seup-modal" id="omotPreviewModal">';
print '<div class="seup-modal-content" style="max-width: 800px; max-height: 90vh;">';
print '<div class="seup-modal-header">';
print '<h5 class="seup-modal-title"><i class="fas fa-eye me-2"></i>Prepregled Omota Spisa</h5>';
print '<button type="button" class="seup-modal-close" id="closeOmotModal">&times;</button>';
print '</div>';
print '<div class="seup-modal-body" style="max-height: 70vh; overflow-y: auto;">';
print '<div id="omotPreviewContent">';
print '<div class="seup-loading-message"><i class="fas fa-spinner fa-spin"></i> Učitavam prepregled...</div>';
print '</div>';
print '</div>';
print '<div class="seup-modal-footer">';
print '<button type="button" class="seup-btn seup-btn-secondary" id="closePreviewBtn">Zatvori</button>';
print '<button type="button" class="seup-btn seup-btn-primary" id="generateFromPreviewBtn">';
print '<i class="fas fa-file-pdf me-2"></i>Generiraj PDF';
print '</button>';
print '</div>';
print '</div>';
print '</div>';

// Print Instructions Modal
print '<div class="seup-modal" id="printInstructionsModal">';
print '<div class="seup-modal-content" style="max-width: 600px;">';
print '<div class="seup-modal-header">';
print '<h5 class="seup-modal-title"><i class="fas fa-print me-2"></i>Upute za Ispis Omota Spisa</h5>';
print '<button type="button" class="seup-modal-close" id="closePrintModal">&times;</button>';
print '</div>';
print '<div class="seup-modal-body">';
print '<div class="seup-print-instructions">';
print '<div class="seup-print-warning">';
print '<div class="seup-warning-icon"><i class="fas fa-exclamation-triangle"></i></div>';
print '<div class="seup-warning-content">';
print '<h4>Važne upute za ispis</h4>';
print '<p>Molimo pažljivo pročitajte upute prije ispisa omota spisa</p>';
print '</div>';
print '</div>';

print '<div class="seup-print-steps">';
print '<div class="seup-print-step">';
print '<div class="seup-step-number">1</div>';
print '<div class="seup-step-content">';
print '<h5>Postavke printera</h5>';
print '<p>Postavite printer na <strong>A3 format papira</strong> (297 x 420 mm)</p>';
print '</div>';
print '</div>';

print '<div class="seup-print-step">';
print '<div class="seup-step-number">2</div>';
print '<div class="seup-step-content">';
print '<h5>Orijentacija</h5>';
print '<p>Odaberite <strong>Portrait</strong> (uspravnu) orijentaciju</p>';
print '</div>';
print '</div>';

print '<div class="seup-print-step">';
print '<div class="seup-step-number">3</div>';
print '<div class="seup-step-content">';
print '<h5>Margine</h5>';
print '<p>Postavite margine na <strong>minimum</strong> ili koristite "Fit to page"</p>';
print '</div>';
print '</div>';

print '<div class="seup-print-step">';
print '<div class="seup-step-number">4</div>';
print '<div class="seup-step-content">';
print '<h5>Preklapanje</h5>';
print '<p>Nakon ispisa, <strong>preklopite papir na pola</strong> da formirate omot</p>';
print '</div>';
print '</div>';
print '</div>';

print '<div class="seup-print-note">';
print '<div class="seup-note-icon"><i class="fas fa-lightbulb"></i></div>';
print '<div class="seup-note-content">';
print '<h5>Napomena</h5>';
print '<p>Omot spisa je dizajniran za A3 papir koji se preklapa na pola. ';
print 'Stranica 1 je naslovnica, stranice 2-3 su unutarnje (popis privitaka), a stranica 4 je zadnja.</p>';
print '</div>';
print '</div>';

print '</div>'; // seup-print-instructions
print '</div>';
print '<div class="seup-modal-footer">';
print '<button type="button" class="seup-btn seup-btn-secondary" id="cancelPrintBtn">Odustani</button>';
print '<button type="button" class="seup-btn seup-btn-primary" id="confirmPrintBtn">';
print '<i class="fas fa-print me-2"></i>Ispiši Omot';
print '</button>';
print '</div>';
print '</div>';
print '</div>';

// Upload Modal
print '<div class="seup-modal" id="uploadModal">';
print '<div class="seup-modal-content" style="max-width: 600px;">';
print '<div class="seup-modal-header">';
print '<h5 class="seup-modal-title"><i class="fas fa-upload me-2"></i>Dodaj Dokument</h5>';
print '<button type="button" class="seup-modal-close" id="closeUploadModal">&times;</button>';
print '</div>';
print '<div class="seup-modal-body">';

print '<form method="post" enctype="multipart/form-data" id="uploadForm">';
print '<input type="hidden" name="action" value="upload_document">';
print '<input type="hidden" name="case_id" value="' . $caseId . '">';

// Upload type selection
print '<div class="seup-form-group">';
print '<label class="seup-form-label">Tip dokumenta:</label>';
print '<div class="seup-radio-group">';
print '<label class="seup-radio-option">';
print '<input type="radio" name="upload_type" value="novi_akt" id="uploadTypeNoviAkt" checked>';
print '<span class="seup-radio-label">Novi AKT</span>';
print '</label>';
print '<label class="seup-radio-option">';
print '<input type="radio" name="upload_type" value="prilog" id="uploadTypePrilog">';
print '<span class="seup-radio-label">PRILOG za postojeći Akt</span>';
print '</label>';
print '<label class="seup-radio-option">';
print '<input type="radio" name="upload_type" value="nedodijeljeno" id="uploadTypeNedodijeljeno">';
print '<span class="seup-radio-label">NEDODIJELJENO</span>';
print '</label>';
print '</div>';
print '</div>';

// Akt selection (for prilozi)
print '<div class="seup-form-group" id="aktSelectionGroup" style="display: none;">';
print '<label class="seup-form-label" for="existing_akt_id">Odaberite Akt:</label>';
print '<select name="existing_akt_id" id="existing_akt_id" class="seup-form-control">';
print '<option value="">Učitavam Aktove...</option>';
print '</select>';
print '</div>';

// Akt details (for novi akt)
print '<div class="seup-form-group" id="aktDetailsGroup">';
print '<label class="seup-form-label" for="akt_naziv">Naziv Akta:</label>';
print '<input type="text" name="akt_naziv" id="akt_naziv" class="seup-form-control" placeholder="Automatski iz naziva datoteke">';
print '<label class="seup-form-label" for="akt_opis">Opis Akta:</label>';
print '<textarea name="akt_opis" id="akt_opis" class="seup-form-control" rows="2" placeholder="Opcionalno"></textarea>';
print '</div>';

// Prilog details (for prilozi)
print '<div class="seup-form-group" id="prilogDetailsGroup" style="display: none;">';
print '<label class="seup-form-label" for="prilog_naziv">Naziv Priloga:</label>';
print '<input type="text" name="prilog_naziv" id="prilog_naziv" class="seup-form-control" placeholder="Automatski iz naziva datoteke">';
print '<label class="seup-form-label" for="prilog_opis">Opis Priloga:</label>';
print '<textarea name="prilog_opis" id="prilog_opis" class="seup-form-control" rows="2" placeholder="Opcionalno"></textarea>';
print '</div>';

// File selection
print '<div class="seup-form-group">';
print '<label class="seup-form-label" for="documentFile">Datoteka:</label>';
print '<input type="file" name="document" id="documentFile" class="seup-form-control" accept=".pdf,.docx,.xlsx,.doc,.xls,.jpg,.jpeg,.png,.odt" required>';
print '</div>';

print '</form>';

print '</div>';
print '<div class="seup-modal-footer">';
print '<button type="button" class="seup-btn seup-btn-secondary" id="cancelUploadBtn">Odustani</button>';
print '<button type="submit" form="uploadForm" class="seup-btn seup-btn-success" id="confirmUploadBtn">';
print '<i class="fas fa-upload me-2"></i>Učitaj';
print '</button>';
print '</div>';
print '</div>';
print '</div>';

// Move to Akt Modal
print '<div class="seup-modal" id="moveToAktModal">';
print '<div class="seup-modal-content" style="max-width: 500px;">';
print '<div class="seup-modal-header">';
print '<h5 class="seup-modal-title"><i class="fas fa-arrow-right me-2"></i>Premjesti u Akt</h5>';
print '<button type="button" class="seup-modal-close" id="closeMoveModal">&times;</button>';
print '</div>';
print '<div class="seup-modal-body">';

print '<div class="seup-move-doc-info">';
print '<div class="seup-move-doc-icon"><i class="fas fa-file-alt"></i></div>';
print '<div class="seup-move-doc-details">';
print '<div class="seup-move-doc-name" id="moveDocName">document.pdf</div>';
print '<div class="seup-move-doc-description">Odaberite Akt u koji želite premjestiti ovaj dokument kao Prilog</div>';
print '</div>';
print '</div>';

print '<div class="seup-form-group">';
print '<label class="seup-form-label" for="move_akt_id">Odaberite Akt:</label>';
print '<select name="move_akt_id" id="move_akt_id" class="seup-form-control" required>';
print '<option value="">Učitavam Aktove...</option>';
print '</select>';
print '</div>';

print '<div class="seup-form-group">';
print '<label class="seup-form-label" for="move_naziv_priloga">Naziv Priloga:</label>';
print '<input type="text" name="move_naziv_priloga" id="move_naziv_priloga" class="seup-form-control" placeholder="Automatski iz naziva datoteke">';
print '</div>';

print '<div class="seup-form-group">';
print '<label class="seup-form-label" for="move_opis_priloga">Opis Priloga:</label>';
print '<textarea name="move_opis_priloga" id="move_opis_priloga" class="seup-form-control" rows="2" placeholder="Opcionalno"></textarea>';
print '</div>';

print '</div>';
print '<div class="seup-modal-footer">';
print '<button type="button" class="seup-btn seup-btn-secondary" id="cancelMoveBtn">Odustani</button>';
print '<button type="button" class="seup-btn seup-btn-primary" id="confirmMoveBtn">';
print '<i class="fas fa-arrow-right me-2"></i>Premjesti';
print '</button>';
print '</div>';
print '</div>';
print '</div>';

// Delete Document Modal
print '<div class="seup-modal" id="deleteDocModal">';
print '<div class="seup-modal-content">';
print '<div class="seup-modal-header">';
print '<h5 class="seup-modal-title"><i class="fas fa-trash me-2"></i>Brisanje Dokumenta</h5>';
print '<button type="button" class="seup-modal-close" id="closeDeleteModal">&times;</button>';
print '</div>';
print '<div class="seup-modal-body">';
print '<div class="seup-delete-doc-info">';
print '<div class="seup-delete-doc-icon"><i class="fas fa-file-alt"></i></div>';
print '<div class="seup-delete-doc-details">';
print '<div class="seup-delete-doc-name" id="deleteDocName">document.pdf</div>';
print '<div class="seup-delete-doc-warning">';
print '<i class="fas fa-exclamation-triangle"></i>';
print 'Jeste li sigurni da želite obrisati ovaj dokument? Ova akcija je nepovratna.';
print '</div>';
print '</div>';
print '</div>';
print '</div>';
print '<div class="seup-modal-footer">';
print '<button type="button" class="seup-btn seup-btn-secondary" id="cancelDeleteBtn">Odustani</button>';
print '<button type="button" class="seup-btn seup-btn-danger" id="confirmDeleteBtn">';
print '<i class="fas fa-trash me-2"></i>Obriši';
print '</button>';
print '</div>';
print '</div>';
print '</div>';

// Delete Akt Modal
print '<div class="seup-modal" id="deleteAktModal">';
print '<div class="seup-modal-content">';
print '<div class="seup-modal-header">';
print '<h5 class="seup-modal-title"><i class="fas fa-trash me-2"></i>Brisanje Akta</h5>';
print '<button type="button" class="seup-modal-close" id="closeDeleteAktModal">&times;</button>';
print '</div>';
print '<div class="seup-modal-body">';
print '<div class="seup-delete-doc-info">';
print '<div class="seup-delete-doc-icon"><i class="fas fa-folder"></i></div>';
print '<div class="seup-delete-doc-details">';
print '<div class="seup-delete-doc-name" id="deleteAktName">AKT 01</div>';
print '<div class="seup-delete-doc-warning">';
print '<i class="fas fa-exclamation-triangle"></i>';
print 'Jeste li sigurni da želite obrisati ovaj Akt i sve njegove Priloge? Ova akcija je nepovratna.';
print '</div>';
print '</div>';
print '</div>';
print '</div>';
print '<div class="seup-modal-footer">';
print '<button type="button" class="seup-btn seup-btn-secondary" id="cancelDeleteAktBtn">Odustani</button>';
print '<button type="button" class="seup-btn seup-btn-danger" id="confirmDeleteAktBtn">';
print '<i class="fas fa-trash me-2"></i>Obriši Akt';
print '</button>';
print '</div>';
print '</div>';
print '</div>';

// Delete Prilog Modal
print '<div class="seup-modal" id="deletePrilogModal">';
print '<div class="seup-modal-content">';
print '<div class="seup-modal-header">';
print '<h5 class="seup-modal-title"><i class="fas fa-trash me-2"></i>Brisanje Priloga</h5>';
print '<button type="button" class="seup-modal-close" id="closeDeletePrilogModal">&times;</button>';
print '</div>';
print '<div class="seup-modal-body">';
print '<div class="seup-delete-doc-info">';
print '<div class="seup-delete-doc-icon"><i class="fas fa-file-alt"></i></div>';
print '<div class="seup-delete-doc-details">';
print '<div class="seup-delete-doc-name" id="deletePrilogName">01-01 Prilog</div>';
print '<div class="seup-delete-doc-warning">';
print '<i class="fas fa-exclamation-triangle"></i>';
print 'Jeste li sigurni da želite obrisati ovaj Prilog? Ova akcija je nepovratna.';
print '</div>';
print '</div>';
print '</div>';
print '</div>';
print '<div class="seup-modal-footer">';
print '<button type="button" class="seup-btn seup-btn-secondary" id="cancelDeletePrilogBtn">Odustani</button>';
print '<button type="button" class="seup-btn seup-btn-danger" id="confirmDeletePrilogBtn">';
print '<i class="fas fa-trash me-2"></i>Obriši Prilog';
print '</button>';
print '</div>';
print '</div>';
print '</div>';

// JavaScript for enhanced functionality
print '<script src="/custom/seup/js/seup-modern.js"></script>';

?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const caseId = <?php echo $caseId; ?>;
    
    // Tab functionality
    const tabs = document.querySelectorAll('.seup-tab');
    const tabPanes = document.querySelectorAll('.seup-tab-pane');

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const targetTab = this.dataset.tab;
            
            // Remove active class from all tabs and panes
            tabs.forEach(t => t.classList.remove('active'));
            tabPanes.forEach(pane => pane.classList.remove('active'));
            
            // Add active class to clicked tab and corresponding pane
            this.classList.add('active');
            document.getElementById(targetTab).classList.add('active');
        });
    });

    // Upload Modal functionality
    const openUploadModalBtn = document.getElementById('openUploadModalBtn');
    const uploadModal = document.getElementById('uploadModal');
    const closeUploadModal = document.getElementById('closeUploadModal');
    const cancelUploadBtn = document.getElementById('cancelUploadBtn');
    const uploadForm = document.getElementById('uploadForm');
    const confirmUploadBtn = document.getElementById('confirmUploadBtn');
    
    // Upload type radio buttons
    const uploadTypeNoviAkt = document.getElementById('uploadTypeNoviAkt');
    const uploadTypePrilog = document.getElementById('uploadTypePrilog');
    const uploadTypeNedodijeljeno = document.getElementById('uploadTypeNedodijeljeno');
    
    // Form groups
    const aktSelectionGroup = document.getElementById('aktSelectionGroup');
    const aktDetailsGroup = document.getElementById('aktDetailsGroup');
    const prilogDetailsGroup = document.getElementById('prilogDetailsGroup');
    
    // Form elements
    const existingAktSelect = document.getElementById('existing_akt_id');
    const dokumentFile = document.getElementById('documentFile');
    const aktNaziv = document.getElementById('akt_naziv');
    const prilogNaziv = document.getElementById('prilog_naziv');
    const moveNazivPriloga = document.getElementById('move_naziv_priloga');

    if (openUploadModalBtn) {
        openUploadModalBtn.addEventListener('click', function() {
            uploadModal.classList.add('show');
            loadAktiForDropdown();
        });
    }
    
    function closeUploadModalFunc() {
        uploadModal.classList.remove('show');
        uploadForm.reset();
        resetUploadFormVisibility();
    }
    
    if (closeUploadModal) closeUploadModal.addEventListener('click', closeUploadModalFunc);
    if (cancelUploadBtn) cancelUploadBtn.addEventListener('click', closeUploadModalFunc);
    
    // Close modal when clicking outside
    uploadModal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeUploadModalFunc();
        }
    });
    
    // Upload type change handlers
    function handleUploadTypeChange() {
        resetUploadFormVisibility();
        
        if (uploadTypeNoviAkt.checked) {
            aktDetailsGroup.style.display = 'block';
        } else if (uploadTypePrilog.checked) {
            aktSelectionGroup.style.display = 'block';
            prilogDetailsGroup.style.display = 'block';
        }
        // nedodijeljeno doesn't need any additional fields
    }
    
    function resetUploadFormVisibility() {
        aktSelectionGroup.style.display = 'none';
        aktDetailsGroup.style.display = 'none';
        prilogDetailsGroup.style.display = 'none';
    }
    
    uploadTypeNoviAkt.addEventListener('change', handleUploadTypeChange);
    uploadTypePrilog.addEventListener('change', handleUploadTypeChange);
    uploadTypeNedodijeljeno.addEventListener('change', handleUploadTypeChange);
    
    // Auto-fill naziv fields when file is selected
    if (dokumentFile) {
        dokumentFile.addEventListener('change', function() {
            if (this.files.length > 0) {
                const filename = this.files[0].name;
                const nameWithoutExt = filename.substring(0, filename.lastIndexOf('.')) || filename;
                
                if (aktNaziv && !aktNaziv.value) {
                    aktNaziv.value = nameWithoutExt;
                }
                if (prilogNaziv && !prilogNaziv.value) {
                    prilogNaziv.value = nameWithoutExt;
                }
                if (moveNazivPriloga && !moveNazivPriloga.value) {
                    moveNazivPriloga.value = nameWithoutExt;
                }
            }
        });
    }
    
    // Load Akti for dropdown
    function loadAktiForDropdown() {
        const selects = [existingAktSelect, document.getElementById('move_akt_id')];
        
        fetch('?action=get_akti_for_predmet', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'predmet_id=' + caseId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                selects.forEach(select => {
                    if (select) {
                        select.innerHTML = '<option value="">Odaberite Akt</option>';
                        data.akti.forEach(akt => {
                            const option = document.createElement('option');
                            option.value = akt.id;
                            option.textContent = akt.display;
                            select.appendChild(option);
                        });
                    }
                });
            } else {
                selects.forEach(select => {
                    if (select) {
                        select.innerHTML = '<option value="">Nema dostupnih Akata</option>';
                    }
                });
            }
        })
        .catch(error => {
            console.error('Error loading Akti:', error);
            selects.forEach(select => {
                if (select) {
                    select.innerHTML = '<option value="">Greška pri učitavanju</option>';
                }
            });
        });
    }

    // Delete Akt functionality
    function closeDeleteAktModal() {
        document.getElementById('deleteAktModal').classList.remove('show');
        currentDeleteAktId = null;
    }

    function confirmDeleteAkt() {
        if (!currentDeleteAktId) return;
        
        const confirmBtn = document.getElementById('confirmDeleteAktBtn');
        confirmBtn.classList.add('seup-loading');
        
        const formData = new FormData();
        formData.append('action', 'delete_akt');
        formData.append('akt_id', currentDeleteAktId);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                closeDeleteAktModal();
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showMessage('Greška pri brisanju: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showMessage('Došlo je do greške pri brisanju Akta', 'error');
        })
        .finally(() => {
            confirmBtn.classList.remove('seup-loading');
        });
    }

    // Delete Prilog functionality
    function closeDeletePrilogModal() {
        document.getElementById('deletePrilogModal').classList.remove('show');
        currentDeletePrilogId = null;
    }

    function confirmDeletePrilog() {
        if (!currentDeletePrilogId) return;
        
        const confirmBtn = document.getElementById('confirmDeletePrilogBtn');
        confirmBtn.classList.add('seup-loading');
        
        const formData = new FormData();
        formData.append('action', 'delete_prilog');
        formData.append('prilog_id', currentDeletePrilogId);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                closeDeletePrilogModal();
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showMessage('Greška pri brisanju: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showMessage('Došlo je do greške pri brisanju Priloga', 'error');
        })
        .finally(() => {
            confirmBtn.classList.remove('seup-loading');
        });
    }

    // Move to Akt functionality
    let currentMoveData = null;

    function closeMoveModal() {
        document.getElementById('moveToAktModal').classList.remove('show');
        currentMoveData = null;
    }

    function confirmMove() {
        if (!currentMoveData) return;
        
        const aktId = document.getElementById('move_akt_id').value;
        const nazivPriloga = document.getElementById('move_naziv_priloga').value;
        const opisPriloga = document.getElementById('move_opis_priloga').value;
        
        if (!aktId) {
            showMessage('Molimo odaberite Akt', 'error');
            return;
        }
        
        const confirmBtn = document.getElementById('confirmMoveBtn');
        confirmBtn.classList.add('seup-loading');
        
        const formData = new FormData();
        formData.append('action', 'move_to_akt');
        formData.append('ecm_id', currentMoveData.ecmId);
        formData.append('akt_id', aktId);
        formData.append('naziv_priloga', nazivPriloga);
        formData.append('opis_priloga', opisPriloga);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                closeMoveModal();
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showMessage('Greška pri premještanju: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showMessage('Došlo je do greške pri premještanju dokumenta', 'error');
        })
        .finally(() => {
            confirmBtn.classList.remove('seup-loading');
        });
    }

    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!dokumentFile.files.length) {
                showMessage('Molimo odaberite datoteku', 'error');
                return;
            }

            // Validate form based on upload type
            if (uploadTypePrilog.checked && !existingAktSelect.value) {
                showMessage('Molimo odaberite Akt za prilog', 'error');
                return;
            }

            confirmUploadBtn.classList.add('seup-loading');

            // Submit form
            const formData = new FormData(this);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    showMessage('Dokument je uspješno učitan', 'success');
                    closeUploadModalFunc();
                    // Reload page to show new document
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showMessage('Greška pri učitavanju dokumenta', 'error');
                }
                showMessage('Greška pri učitavanju dokumenta', 'error');
            })
            .finally(() => {
                confirmUploadBtn.classList.remove('seup-loading');
            });
        });
    }

    // Omot generation functionality
    const generateOmotBtn = document.getElementById('generateOmotBtn');
    const previewOmotBtn = document.getElementById('previewOmotBtn');
    const printOmotBtn = document.getElementById('printOmotBtn');

    if (generateOmotBtn) {
        generateOmotBtn.addEventListener('click', function() {
            this.classList.add('seup-loading');
            
            const formData = new FormData();
            formData.append('action', 'generate_omot');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    // Reload documents list to show new omot
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showMessage('Greška pri generiranju omota: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showMessage('Došlo je do greške pri generiranju omota', 'error');
            })
            .finally(() => {
                this.classList.remove('seup-loading');
            });
        });
    }

    if (previewOmotBtn) {
        previewOmotBtn.addEventListener('click', function() {
            openOmotPreview();
        });
    }

    if (printOmotBtn) {
        printOmotBtn.addEventListener('click', function() {
            openPrintInstructionsModal();
        });
    }

    // Print Instructions Modal functionality
    function openPrintInstructionsModal() {
        const modal = document.getElementById('printInstructionsModal');
        modal.classList.add('show');
    }

    function closePrintInstructionsModal() {
        const modal = document.getElementById('printInstructionsModal');
        modal.classList.remove('show');
    }

    function confirmPrint() {
        closePrintInstructionsModal();
        
        // Show loading message
        showMessage('Priprema omot za ispis...', 'success', 2000);
        
        // Small delay then print
        setTimeout(() => {
            window.print();
        }, 500);
    }

    // Print modal event listeners
    document.getElementById('closePrintModal').addEventListener('click', closePrintInstructionsModal);
    document.getElementById('cancelPrintBtn').addEventListener('click', closePrintInstructionsModal);
    document.getElementById('confirmPrintBtn').addEventListener('click', confirmPrint);

    // Close print modal when clicking outside
    document.getElementById('printInstructionsModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closePrintInstructionsModal();
        }
    });

    // Omot preview modal functionality
    function openOmotPreview() {
        const modal = document.getElementById('omotPreviewModal');
        const content = document.getElementById('omotPreviewContent');
        
        modal.classList.add('show');
        content.innerHTML = '<div class="seup-loading-message"><i class="fas fa-spinner fa-spin"></i> Učitavam prepregled...</div>';
        
        const formData = new FormData();
        formData.append('action', 'preview_omot');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                content.innerHTML = data.preview_html;
            } else {
                content.innerHTML = '<div class="seup-alert seup-alert-error">Greška pri učitavanju prepregleda: ' + data.error + '</div>';
            }
        })
        .catch(error => {
            content.innerHTML = '<div class="seup-alert seup-alert-error">Došlo je do greške pri učitavanju prepregleda</div>';
        });
    }

    function closeOmotPreview() {
        document.getElementById('omotPreviewModal').classList.remove('show');
    }

    // Modal event listeners
    document.getElementById('closeOmotModal').addEventListener('click', closeOmotPreview);
    document.getElementById('closePreviewBtn').addEventListener('click', closeOmotPreview);

    document.getElementById('generateFromPreviewBtn').addEventListener('click', function() {
        closeOmotPreview();
        generateOmotBtn.click();
    });

    // Close modal when clicking outside
    document.getElementById('omotPreviewModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeOmotPreview();
        }
    });

    // Document deletion functionality
    let currentDeleteData = null;
    let currentDeleteAktId = null;
    let currentDeletePrilogId = null;

    document.addEventListener('click', function(e) {
        // Handle regular document deletion (nedodijeljena)
        if (e.target.closest('.delete-document-btn')) {
            const btn = e.target.closest('.delete-document-btn');
            const filename = btn.dataset.filename;
            const filepath = btn.dataset.filepath;
            
            currentDeleteData = { filename, filepath };
            
            // Update modal content
            document.getElementById('deleteDocName').textContent = filename;
            
            // Show modal
            document.getElementById('deleteDocModal').classList.add('show');
        }
        
        // Handle Akt deletion
        if (e.target.closest('.delete-akt-btn')) {
            const btn = e.target.closest('.delete-akt-btn');
            const aktId = btn.dataset.aktId;
            const filename = btn.dataset.filename;
            
            currentDeleteAktId = aktId;
            
            // Update modal content
            document.getElementById('deleteAktName').textContent = filename;
            
            // Show modal
            document.getElementById('deleteAktModal').classList.add('show');
        }
        
        // Handle Prilog deletion
        if (e.target.closest('.delete-prilog-btn')) {
            const btn = e.target.closest('.delete-prilog-btn');
            const prilogId = btn.dataset.prilogId;
            const filename = btn.dataset.filename;
            
            currentDeletePrilogId = prilogId;
            
            // Update modal content
            document.getElementById('deletePrilogName').textContent = filename;
            
            // Show modal
            document.getElementById('deletePrilogModal').classList.add('show');
        }
        
        // Handle Move to Akt
        if (e.target.closest('.move-to-akt-btn')) {
            const btn = e.target.closest('.move-to-akt-btn');
            const ecmId = btn.dataset.ecmId;
            const filename = btn.dataset.filename;
            
            currentMoveData = { ecmId, filename };
            
            // Update modal content
            document.getElementById('moveDocName').textContent = filename;
            document.getElementById('move_naziv_priloga').value = filename.substring(0, filename.lastIndexOf('.')) || filename;
            
            // Load Akti and show modal
            loadAktiForDropdown();
            document.getElementById('moveToAktModal').classList.add('show');
        }
    });

    function closeDeleteModal() {
        document.getElementById('deleteDocModal').classList.remove('show');
        currentDeleteData = null;
    }

    function confirmDelete() {
        if (!currentDeleteData) return;
        
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        confirmBtn.classList.add('seup-loading');
        
        const formData = new FormData();
        formData.append('action', 'delete_document');
        formData.append('filename', currentDeleteData.filename);
        formData.append('filepath', currentDeleteData.filepath);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                closeDeleteModal();
                // Reload page to update document list
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showMessage('Greška pri brisanju: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showMessage('Došlo je do greške pri brisanju dokumenta', 'error');
        })
        .finally(() => {
            confirmBtn.classList.remove('seup-loading');
        });
    }

    // Delete modal event listeners
    document.getElementById('closeDeleteModal').addEventListener('click', closeDeleteModal);
    document.getElementById('cancelDeleteBtn').addEventListener('click', closeDeleteModal);
    document.getElementById('confirmDeleteBtn').addEventListener('click', confirmDelete);

    // Close delete modal when clicking outside
    document.getElementById('deleteDocModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeDeleteModal();
        }
    });

    // Delete Akt modal event listeners
    document.getElementById('closeDeleteAktModal').addEventListener('click', closeDeleteAktModal);
    document.getElementById('cancelDeleteAktBtn').addEventListener('click', closeDeleteAktModal);
    document.getElementById('confirmDeleteAktBtn').addEventListener('click', confirmDeleteAkt);

    document.getElementById('deleteAktModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeDeleteAktModal();
        }
    });

    // Delete Prilog modal event listeners
    document.getElementById('closeDeletePrilogModal').addEventListener('click', closeDeletePrilogModal);
    document.getElementById('cancelDeletePrilogBtn').addEventListener('click', closeDeletePrilogModal);
    document.getElementById('confirmDeletePrilogBtn').addEventListener('click', confirmDeletePrilog);

    document.getElementById('deletePrilogModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeDeletePrilogModal();
        }
    });

    // Move modal event listeners
    document.getElementById('closeMoveModal').addEventListener('click', closeMoveModal);
    document.getElementById('cancelMoveBtn').addEventListener('click', closeMoveModal);
    document.getElementById('confirmMoveBtn').addEventListener('click', confirmMove);

    document.getElementById('moveToAktModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeMoveModal();
        }
    });
    // Toast message function
    window.showMessage = function(message, type = 'success', duration = 5000) {
        let messageEl = document.querySelector('.seup-message-toast');
        if (!messageEl) {
            messageEl = document.createElement('div');
            messageEl.className = 'seup-message-toast';
            document.body.appendChild(messageEl);
        }

        messageEl.className = `seup-message-toast seup-message-${type} show`;
        messageEl.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
            ${message}
        `;

        setTimeout(() => {
            messageEl.classList.remove('show');
        }, duration);
    };
});
</script>

<style>
/* Hierarchical table styles */
.seup-hierarchical-table .seup-document-number {
    font-weight: var(--font-bold);
    color: var(--primary-600);
    font-size: var(--text-sm);
}

.seup-hierarchical-table .seup-akt-row {
    background-color: var(--primary-50);
    border-left: 4px solid var(--primary-500);
}

.seup-hierarchical-table .seup-prilog-row {
    background-color: var(--neutral-25);
}

.seup-hierarchical-table .seup-nedodijeljeno-row {
    background-color: var(--warning-25);
}

.seup-hierarchical-table .seup-section-header {
    background-color: var(--secondary-100);
    border-left: 4px solid var(--secondary-500);
}

.seup-hierarchical-table .seup-section-title {
    font-weight: var(--font-bold);
    color: var(--secondary-700);
    font-size: var(--text-base);
}

.seup-hierarchical-table .seup-document-indent {
    margin-left: var(--space-6);
    padding-left: var(--space-3);
    border-left: 2px solid var(--neutral-300);
}

.seup-hierarchical-table .seup-document-description {
    font-style: italic;
    color: var(--secondary-600);
}

.seup-hierarchical-table .seup-indent-1 {
    position: relative;
}

.seup-hierarchical-table .seup-indent-1::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background-color: var(--neutral-300);
}

/* Upload modal styles */
.seup-radio-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.seup-radio-option {
    display: flex;
    align-items: center;
    padding: var(--space-3);
    border: 2px solid var(--neutral-300);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s ease;
}

.seup-radio-option:hover {
    border-color: var(--primary-400);
    background-color: var(--primary-25);
}

.seup-radio-option input[type="radio"] {
    margin-right: var(--space-3);
}

.seup-radio-option input[type="radio"]:checked + .seup-radio-label {
    font-weight: var(--font-semibold);
    color: var(--primary-700);
}

.seup-radio-option:has(input[type="radio"]:checked) {
    border-color: var(--primary-500);
    background-color: var(--primary-50);
}

.seup-form-group {
    margin-bottom: var(--space-4);
}

.seup-form-label {
    display: block;
    margin-bottom: var(--space-2);
    font-weight: var(--font-medium);
    color: var(--secondary-700);
}

.seup-form-control {
    width: 100%;
    padding: var(--space-3);
    border: 2px solid var(--neutral-300);
    border-radius: var(--radius-md);
    font-size: var(--text-base);
    transition: border-color 0.2s ease;
}

.seup-form-control:focus {
    outline: none;
    border-color: var(--primary-500);
    box-shadow: 0 0 0 3px var(--primary-100);
}

/* Move modal styles */
.seup-move-doc-info {
    display: flex;
    align-items: center;
    padding: var(--space-4);
    background-color: var(--neutral-50);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-4);
}

.seup-move-doc-icon {
    font-size: var(--text-2xl);
    color: var(--primary-500);
    margin-right: var(--space-4);
}

.seup-move-doc-name {
    font-weight: var(--font-semibold);
    color: var(--secondary-800);
    margin-bottom: var(--space-1);
}

.seup-move-doc-description {
    color: var(--secondary-600);
    font-size: var(--text-sm);
}

/* Document action buttons */
.seup-document-btn-move {
    background-color: var(--success-500);
    color: white;
}

.seup-document-btn-move:hover {
    background-color: var(--success-600);
}

/* Omat Preview Modal Styles */
.seup-omat-preview {
  font-family: var(--font-family-sans);
}

.seup-omat-page {
  background: white;
  border: 1px solid var(--neutral-300);
  border-radius: var(--radius-lg);
  padding: var(--space-6);
  margin-bottom: var(--space-4);
  box-shadow: var(--shadow-sm);
}

.seup-omat-title {
  text-align: center;
  font-size: var(--text-2xl);
  font-weight: var(--font-bold);
  color: var(--secondary-900);
  margin-bottom: var(--space-6);
  padding-bottom: var(--space-3);
  border-bottom: 2px solid var(--primary-500);
}

.seup-omat-section {
  margin-bottom: var(--space-4);
  padding: var(--space-3);
  background: var(--neutral-50);
  border-radius: var(--radius-md);
  border-left: 4px solid var(--primary-500);
}

.seup-omat-section h4 {
  font-size: var(--text-base);
  font-weight: var(--font-bold);
  color: var(--secondary-800);
  margin-bottom: var(--space-2);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.seup-omat-section p {
  font-size: var(--text-base);
  color: var(--secondary-700);
  margin: 0;
  line-height: var(--leading-relaxed);
}

.seup-omat-desc {
  font-style: italic;
  color: var(--secondary-600);
  font-size: var(--text-sm);
  margin-top: var(--space-1);
}

.seup-omat-meta {
  background: var(--primary-50);
  border: 1px solid var(--primary-200);
  border-radius: var(--radius-md);
  padding: var(--space-4);
  margin-top: var(--space-4);
}

.seup-omat-meta p {
  margin-bottom: var(--space-2);
  font-size: var(--text-sm);
}

.seup-omat-meta p:last-child {
  margin-bottom: 0;
}

.seup-omat-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: var(--space-3);
  font-size: var(--text-sm);
}

.seup-omat-table th {
  background: var(--primary-500);
  color: white;
  padding: var(--space-3);
  text-align: left;
  font-weight: var(--font-semibold);
  border: 1px solid var(--primary-600);
}

.seup-omat-table td {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--neutral-300);
  background: white;
}

.seup-omat-table tr:nth-child(even) td {
  background: var(--neutral-25);
}

.seup-omat-empty {
  text-align: center;
  color: var(--secondary-500);
  font-style: italic;
  padding: var(--space-6);
}

.seup-loading-message {
  text-align: center;
  padding: var(--space-6);
  color: var(--primary-600);
  font-weight: var(--font-medium);
}

/* Enhanced modal for larger content */
#omatPreviewModal .seup-modal-content {
  max-width: 900px;
  width: 95%;
}

#omatPreviewModal .seup-modal-body {
  max-height: 70vh;
  overflow-y: auto;
  padding: var(--space-4);
}

/* Print styles for omat */
@media print {
  .seup-omat-preview {
    font-size: 12pt;
    line-height: 1.4;
  }
  
  .seup-omat-page {
    page-break-after: always;
    border: none;
    box-shadow: none;
    margin: 0;
    padding: 20mm;
  }
  
  .seup-omat-page:last-child {
    page-break-after: auto;
  }
  
  .seup-omat-title {
    font-size: 18pt;
    margin-bottom: 20mm;
  }
  
  .seup-omat-section {
    margin-bottom: 15mm;
    background: none;
    border: none;
    border-left: 2pt solid black;
    padding-left: 10mm;
  }
  
  .seup-omat-section h4 {
    font-size: 12pt;
    margin-bottom: 5mm;
  }
  
  .seup-omat-table {
    font-size: 10pt;
  }
  
  .seup-omat-table th {
    background: #f0f0f0 !important;
    color: black !important;
  }
}
</style>

<?php
llxFooter();
$db->close();
?>