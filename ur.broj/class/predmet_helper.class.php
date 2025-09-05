@@ .. @@
     */
    public static function createSeupDatabaseTables($db)
    {
+        // Create Akti tables first
+        require_once __DIR__ . '/akti_helper.class.php';
+        Akti_helper::createAktiDatabaseTables($db);
+        
         $sql_tables = [
             "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "a_oznaka_ustanove (
@@ .. @@
     /**
     * Fetch uploaded documents for a predmet
     */
-    public static function fetchUploadedDocuments($db, $conf, &$documentTableHTML, $langs, $caseId)
+    public static function fetchUploadedDocuments($db, $conf, &$documentTableHTML, $langs, $caseId, $hierarchical = true)
     {
         // Ensure digital signature columns exist
         require_once __DIR__ . '/digital_signature_detector.class.php';
         Digital_Signature_Detector::ensureDigitalSignatureColumns($db);
         
         // Auto-scan ECM if enabled
         if (getDolGlobalString('SEUP_ECM_AUTO_SCAN', '0') === '1') {
             require_once __DIR__ . '/ecm_scanner.class.php';
             $scanResult = ECM_Scanner::scanPredmetFolder($db, $conf, $GLOBALS['user'], $caseId);
             if ($scanResult['success'] && $scanResult['files_added'] > 0) {
                 dol_syslog("Auto-scan added " . $scanResult['files_added'] . " files to ECM", LOG_INFO);
             }
         }
         
-        // Get documents from both Dolibarr ECM and Nextcloud
-        $documents = self::getCombinedDocuments($db, $conf, $caseId);
+        // Get documents - hierarchical or flat view
+        if ($hierarchical) {
+            require_once __DIR__ . '/akti_helper.class.php';
+            $documents = Akti_helper::getHierarchicalDocuments($db, $conf, $caseId);
+        } else {
+            $documents = self::getCombinedDocuments($db, $conf, $caseId);
+        }

         if (count($documents) > 0) {
-            $documentTableHTML = '<table class="table table-striped table-hover align-middle table-bordered seup-documents-table">';
-            $documentTableHTML .= '<colgroup>' .'<col style="width:45%">' .'<col style="width:10%">' .'<col style="width:15%">' .'<col style="width:15%">' .'<col style="width:10%">' .'<col style="width:5%">' .'</colgroup>'; $documentTableHTML .= '<thead class="table-dark">';
+            $documentTableHTML = '<table class="table table-striped table-hover align-middle table-bordered seup-documents-table seup-hierarchical-table">';
+            $documentTableHTML .= '<colgroup>' .'<col style="width:10%">' .'<col style="width:35%">' .'<col style="width:10%">' .'<col style="width:15%">' .'<col style="width:15%">' .'<col style="width:10%">' .'<col style="width:5%">' .'</colgroup>'; 
+            $documentTableHTML .= '<thead class="table-dark">';
             $documentTableHTML .= '<tr>';
+            $documentTableHTML .= '<th>Broj</th>';
             $documentTableHTML .= '<th>Naziv datoteke</th>';
             $documentTableHTML .= '<th class="text-end text-nowrap">Veličina</th>';
             $documentTableHTML .= '<th class="text-nowrap">Datum</th>';
             $documentTableHTML .= '<th class="text-nowrap">Kreirao</th>';
             $documentTableHTML .= '<th class="text-nowrap">Digitalni potpis</th>';
             $documentTableHTML .= '<th class="text-nowrap text-end">Akcije</th>';
             $documentTableHTML .= '</tr>';
             $documentTableHTML .= '</thead>';
             $documentTableHTML .= '<tbody>';

             foreach ($documents as $doc) {
+                // Skip auto-scan for hierarchical headers
+                if (isset($doc->type) && $doc->type === 'nedodijeljeno_header') {
+                    // Render header row
+                    $documentTableHTML .= '<tr class="seup-section-header">';
+                    $documentTableHTML .= '<td></td>';
+                    $documentTableHTML .= '<td colspan="6">';
+                    $documentTableHTML .= '<div class="seup-section-title">';
+                    $documentTableHTML .= '<i class="fas fa-folder-open me-2"></i>';
+                    $documentTableHTML .= htmlspecialchars($doc->naziv);
+                    $documentTableHTML .= '</div>';
+                    $documentTableHTML .= '</td>';
+                    $documentTableHTML .= '</tr>';
+                    continue;
+                }
+                
                 // Auto-scan PDF for signature if not scanned yet
-                if (isset($doc->filepath) && strtolower(pathinfo($doc->filename, PATHINFO_EXTENSION)) === 'pdf') {
-                    if (!isset($doc->digital_signature) || $doc->digital_signature === null) {
-                        $full_path = DOL_DATA_ROOT . '/ecm/' . rtrim($doc->filepath, '/') . '/' . $doc->filename;
-                        if (file_exists($full_path) && isset($doc->rowid)) {
-                            $scanResult = Digital_Signature_Detector::autoScanOnUpload($db, $conf, $full_path, $doc->rowid);
+                if (isset($doc->filepath) && isset($doc->filename) && strtolower(pathinfo($doc->filename, PATHINFO_EXTENSION)) === 'pdf') {
+                    if (!isset($doc->digital_signature) || $doc->digital_signature === null) {
+                        $full_path = DOL_DATA_ROOT . '/ecm/' . rtrim($doc->filepath, '/') . '/' . $doc->filename;
+                        if (file_exists($full_path) && isset($doc->ecm_id)) {
+                            $scanResult = Digital_Signature_Detector::autoScanOnUpload($db, $conf, $full_path, $doc->ecm_id);
                             if ($scanResult['success'] && $scanResult['has_signature']) {
                                 // Refresh document data
                                 $doc->digital_signature = 1;
                                 $doc->signature_status = 'valid';
                                 $doc->signer_name = $scanResult['signature_info']['signer_name'] ?? null;
                             }
                         }
                     }
                 }

-                // Handle different document sources
-                if (isset($doc->source) && $doc->source === 'nextcloud') {
-                    if ($doc->edit_url) {
-                        $documentTableHTML .= '<a href="' . $doc->edit_url . '" class="seup-document-action-btn seup-document-btn-edit" target="_blank" title="Uredi u Nextcloud">';
-                        $documentTableHTML .= '<i class="fas fa-edit"></i>';
-                        $documentTableHTML .= '</a>';
-                    }
-                } else {
-                    // No edit button for Dolibarr ECM documents for now
-                }
-                
-                $documentTableHTML .= '<tr>';
+                // Determine row class based on type and indent level
+                $row_class = 'seup-document-row';
+                if (isset($doc->type)) {
+                    $row_class .= ' seup-' . $doc->type . '-row';
+                }
+                if (isset($doc->indent_level) && $doc->indent_level > 0) {
+                    $row_class .= ' seup-indent-' . $doc->indent_level;
+                }
+                
+                $documentTableHTML .= '<tr class="' . $row_class . '">';
+                
+                // Document number column
+                $documentTableHTML .= '<td class="text-center">';
+                if (isset($doc->display_number)) {
+                    $documentTableHTML .= '<span class="seup-document-number">' . htmlspecialchars($doc->display_number) . '</span>';
+                }
+                $documentTableHTML .= '</td>';
+                
+                // Filename column with indentation
                 $documentTableHTML .= '<td class=\"align-middle\">';
+                
+                // Add indentation for prilozi and nedodijeljena
+                if (isset($doc->indent_level) && $doc->indent_level > 0) {
+                    $documentTableHTML .= '<div class="seup-document-indent">';
+                }
+                
                 $documentTableHTML .= '<i class="' . self::getFileIcon($doc->filename) . ' me-2"></i>';
                 $documentTableHTML .= htmlspecialchars($doc->filename);
-                if (isset($doc->tags) && !empty($doc->tags)) {
-                    $documentTableHTML .= '<br><small class="text-muted"><i class="fas fa-tags"></i> ' . htmlspecialchars($doc->tags) . '</small>';
+                
+                // Show naziv/opis for Akti and Prilozi
+                if (isset($doc->naziv) && !empty($doc->naziv)) {
+                    $documentTableHTML .= '<br><small class="text-muted seup-document-description">' . htmlspecialchars($doc->naziv) . '</small>';
+                }
+                
+                if (isset($doc->indent_level) && $doc->indent_level > 0) {
+                    $documentTableHTML .= '</div>';
                 }
+                
                 $documentTableHTML .= '</td>';
                 
                 // File size
                 $file_size = 'N/A';
                 if (isset($doc->size) && $doc->size > 0) {
                     $file_size = self::formatFileSize($doc->size);
                 } elseif (isset($doc->filepath)) {
                     $full_path = DOL_DATA_ROOT . '/ecm/' . rtrim($doc->filepath, '/') . '/' . $doc->filename;
                     if (file_exists($full_path)) {
                         $file_size = self::formatFileSize(filesize($full_path));
                     }
                 }
                 $documentTableHTML .= '<td><span class="seup-document-size">' . $file_size . '</span></td>';
                 
-                // Date formatting (fixed with robust fallbacks)
-$date_formatted = '—';
-$full_path = DOL_DATA_ROOT . '/ecm/' . rtrim($doc->filepath ?? '', '/') . '/' . $doc->filename;
-
-if (!empty($doc->date_c)) {
-    // $doc->date_c may be timestamp or string
-    $ts = is_numeric($doc->date_c) ? (int) $doc->date_c
-        : (function_exists('dol_stringtotime') ? dol_stringtotime($doc->date_c) : strtotime($doc->date_c));
-    if ($ts) $date_formatted = dol_print_date($ts, '%d.%m.%Y %H:%M');
-} elseif (!empty($doc->last_modified)) {
-    $ts = strtotime($doc->last_modified);
-    if ($ts) $date_formatted = dol_print_date($ts, '%d.%m.%Y %H:%M');
-} else {
-    // Fallback to filesystem mtime if available
-    if (is_readable($full_path)) {
-        $ts = @filemtime($full_path);
-        if ($ts) $date_formatted = dol_print_date($ts, '%d.%m.%Y %H:%M');
-    }
-}
+                // Date formatting
+                $date_formatted = '—';
+                $full_path = DOL_DATA_ROOT . '/ecm/' . rtrim($doc->filepath ?? '', '/') . '/' . $doc->filename;
+
+                if (!empty($doc->date_c)) {
+                    $ts = is_numeric($doc->date_c) ? (int) $doc->date_c
+                        : (function_exists('dol_stringtotime') ? dol_stringtotime($doc->date_c) : strtotime($doc->date_c));
+                    if ($ts) $date_formatted = dol_print_date($ts, '%d.%m.%Y %H:%M');
+                } elseif (!empty($doc->last_modified)) {
+                    $ts = strtotime($doc->last_modified);
+                    if ($ts) $date_formatted = dol_print_date($ts, '%d.%m.%Y %H:%M');
+                } else {
+                    if (is_readable($full_path)) {
+                        $ts = @filemtime($full_path);
+                        if ($ts) $date_formatted = dol_print_date($ts, '%d.%m.%Y %H:%M');
+                    }
+                }

                 $documentTableHTML .= '<td class=\"text-nowrap align-middle\"><div class=\"seup-document-date\"><i class="fas fa-calendar me-1"></i>' . $date_formatted . '</div></td>';
                 
                 // Created by
                 $created_by = $doc->created_by ?? 'N/A';
                 $documentTableHTML .= '<td class=\"text-nowrap align-middle\"><div class=\"seup-document-user\"><i class="fas fa-user me-1"></i>' . htmlspecialchars($created_by) . '</div></td>';
                 
                 // Digital signature status
                 $documentTableHTML .= '<td>';
                 if (isset($doc->digital_signature) && $doc->digital_signature == 1) {
                     $signatureBadge = Digital_Signature_Detector::getSignatureBadge(
                         true, 
                         $doc->signature_status ?? 'unknown',
                         $doc->signer_name ?? null
                     );
                     $documentTableHTML .= $signatureBadge;
                 } else {
                     $documentTableHTML .= '<span class="seup-signature-none"><i class="fas fa-minus-circle"></i> Nije potpisan</span>';
                 }
                 $documentTableHTML .= '</td>';
                 
-                $documentTableHTML .= '<td class=\"text-end align-middle\">'; $documentTableHTML .= '<div class=\"seup-document-actions d-inline-flex gap-2 flex-nowrap\">';
+                $documentTableHTML .= '<td class=\"text-end align-middle\">';
+                $documentTableHTML .= '<div class=\"seup-document-actions d-inline-flex gap-2 flex-nowrap\">';
                 
                 // Download button
                 $relative_path = self::getPredmetFolderPath($caseId, $db);
                 $download_url = DOL_URL_ROOT . '/document.php?modulepart=ecm&file=' . urlencode($relative_path . $doc->filename);
                 $documentTableHTML .= '<a href="' . $download_url . '" class="seup-document-btn seup-document-btn-download" target="_blank" title="Preuzmi">';
                 $documentTableHTML .= '<i class="fas fa-download"></i>';
                 $documentTableHTML .= '</a>';
                 
                 // Edit button (if available)
                 if (isset($doc->edit_url) && !empty($doc->edit_url)) {
                     $documentTableHTML .= '<a href="' . $doc->edit_url . '" class="seup-document-btn seup-document-btn-edit" target="_blank" title="Uredi u Nextcloud">';
                     $documentTableHTML .= '<i class="fas fa-edit"></i>';
                     $documentTableHTML .= '</a>';
                 }
                 
-                // Delete button
-                $documentTableHTML .= '<button class="seup-document-btn seup-document-btn-delete delete-document-btn" ';
-                $documentTableHTML .= 'data-filename="' . htmlspecialchars($doc->filename) . '" ';
-                $documentTableHTML .= 'data-filepath="' . htmlspecialchars($doc->filepath ?? $relative_path) . '" ';
-                $documentTableHTML .= 'title="Obriši dokument">';
-                $documentTableHTML .= '<i class="fas fa-trash"></i>';
-                $documentTableHTML .= '</button>';
+                // Delete button with different handling for Akti vs Prilozi vs Nedodijeljena
+                if (isset($doc->type)) {
+                    if ($doc->type === 'akt') {
+                        $documentTableHTML .= '<button class="seup-document-btn seup-document-btn-delete delete-akt-btn" ';
+                        $documentTableHTML .= 'data-akt-id="' . $doc->akt_id . '" ';
+                        $documentTableHTML .= 'data-filename="' . htmlspecialchars($doc->filename) . '" ';
+                        $documentTableHTML .= 'title="Obriši Akt i sve priloge">';
+                        $documentTableHTML .= '<i class="fas fa-trash"></i>';
+                        $documentTableHTML .= '</button>';
+                    } elseif ($doc->type === 'prilog') {
+                        $documentTableHTML .= '<button class="seup-document-btn seup-document-btn-delete delete-prilog-btn" ';
+                        $documentTableHTML .= 'data-prilog-id="' . $doc->prilog_id . '" ';
+                        $documentTableHTML .= 'data-filename="' . htmlspecialchars($doc->filename) . '" ';
+                        $documentTableHTML .= 'title="Obriši prilog">';
+                        $documentTableHTML .= '<i class="fas fa-trash"></i>';
+                        $documentTableHTML .= '</button>';
+                    } elseif ($doc->type === 'nedodijeljeno') {
+                        $documentTableHTML .= '<button class="seup-document-btn seup-document-btn-delete delete-document-btn" ';
+                        $documentTableHTML .= 'data-filename="' . htmlspecialchars($doc->filename) . '" ';
+                        $documentTableHTML .= 'data-filepath="' . htmlspecialchars($doc->filepath ?? $relative_path) . '" ';
+                        $documentTableHTML .= 'title="Obriši dokument">';
+                        $documentTableHTML .= '<i class="fas fa-trash"></i>';
+                        $documentTableHTML .= '</button>';
+                        
+                        // Move to Akt button for nedodijeljena documents
+                        $documentTableHTML .= '<button class="seup-document-btn seup-document-btn-move move-to-akt-btn" ';
+                        $documentTableHTML .= 'data-ecm-id="' . $doc->ecm_id . '" ';
+                        $documentTableHTML .= 'data-filename="' . htmlspecialchars($doc->filename) . '" ';
+                        $documentTableHTML .= 'title="Premjesti u Akt">';
+                        $documentTableHTML .= '<i class="fas fa-arrow-right"></i>';
+                        $documentTableHTML .= '</button>';
+                    }
+                } else {
+                    // Fallback for old format
+                    $documentTableHTML .= '<button class="seup-document-btn seup-document-btn-delete delete-document-btn" ';
+                    $documentTableHTML .= 'data-filename="' . htmlspecialchars($doc->filename) . '" ';
+                    $documentTableHTML .= 'data-filepath="' . htmlspecialchars($doc->filepath ?? $relative_path) . '" ';
+                    $documentTableHTML .= 'title="Obriši dokument">';
+                    $documentTableHTML .= '<i class="fas fa-trash"></i>';
+                    $documentTableHTML .= '</button>';
+                }
                 
                 $documentTableHTML .= '</div>'; // seup-document-actions
                 $documentTableHTML .= '</td>';
                 $documentTableHTML .= '</tr>';
             }

             $documentTableHTML .= '</tbody>';
-            $documentTableHTML .= '</table>' . '\n/* seup-documents-table tidy */\n<style>\n.seup-documents-table td, .seup-documents-table th { vertical-align: middle; }\n.seup-documents-table .nowrap { white-space: nowrap; }\n.seup-documents-table .text-truncate { max-width: 100%; overflow: hidden; text-overflow: ellipsis; }\n</style>\n';
+            $documentTableHTML .= '</table>';
         } else {
             $documentTableHTML = '<div class="alert alert-info">';
             $documentTableHTML .= '<i class="fas fa-info-circle me-2"></i>';
             $documentTableHTML .= $langs->trans("NoDocumentsFound");
             $documentTableHTML .= '</div>';
         }
     }