<?php

/**
 * Plaćena licenca
 * (c) 2025 8Core Association
 * Tomislav Galić <tomislav@8core.hr>
 * Marko Šimunović <marko@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zaštićen je autorskim i srodnim pravima 
 * te ga je izričito zabranjeno umnožavati, distribuirati, mijenjati, objavljivati ili 
 * na drugi način eksploatirati bez pismenog odobrenja autora.
 */

/**
 * Akti Helper Class for SEUP Module
 * Handles Akti and Prilozi management with hierarchical structure
 */
class Akti_helper
{
    /**
     * Create Akti and Prilozi database tables if they don't exist
     */
    public static function createAktiDatabaseTables($db)
    {
        $sql_tables = [
            // Tablica za Aktove (glavni dokumenti)
            "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "a_akti (
                ID_akta int(11) NOT NULL AUTO_INCREMENT,
                fk_predmet int(11) NOT NULL,
                akt_rbr int(11) NOT NULL,
                naziv_akta varchar(255) NOT NULL,
                opis_akta text,
                fk_ecmfile int(11) NOT NULL,
                datum_kreiranja timestamp DEFAULT CURRENT_TIMESTAMP,
                fk_user_kreirao int(11) NOT NULL,
                entity int(11) NOT NULL DEFAULT 1,
                PRIMARY KEY (ID_akta),
                UNIQUE KEY unique_akt_predmet (fk_predmet, akt_rbr),
                KEY fk_predmet_akt (fk_predmet),
                KEY fk_ecmfile_akt (fk_ecmfile),
                KEY fk_user_akt (fk_user_kreirao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8",

            // Tablica za Priloge vezane uz Aktove
            "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "a_akti_prilozi (
                ID_priloga int(11) NOT NULL AUTO_INCREMENT,
                fk_akt int(11) NOT NULL,
                fk_ecmfile int(11) NOT NULL,
                prilog_rbr int(11) NOT NULL,
                naziv_priloga varchar(255) NOT NULL,
                opis_priloga text,
                datum_kreiranja timestamp DEFAULT CURRENT_TIMESTAMP,
                fk_user_kreirao int(11) NOT NULL,
                entity int(11) NOT NULL DEFAULT 1,
                PRIMARY KEY (ID_priloga),
                UNIQUE KEY unique_prilog_akt (fk_akt, prilog_rbr),
                KEY fk_akt_prilog (fk_akt),
                KEY fk_ecmfile_prilog (fk_ecmfile),
                KEY fk_user_prilog (fk_user_kreirao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        ];

        foreach ($sql_tables as $sql) {
            $resql = $db->query($sql);
            if (!$resql) {
                dol_syslog("Error creating Akti table: " . $db->lasterror(), LOG_ERR);
                return false;
            }
        }
        
        dol_syslog("Akti database tables created successfully", LOG_INFO);
        return true;
    }

    /**
     * Get next Akt sequential number for predmet
     */
    public static function getNextAktRbr($db, $predmet_id)
    {
        $sql = "SELECT MAX(akt_rbr) as max_rbr 
                FROM " . MAIN_DB_PREFIX . "a_akti 
                WHERE fk_predmet = " . (int)$predmet_id;

        $resql = $db->query($sql);
        if ($resql && $obj = $db->fetch_object($resql)) {
            return ($obj->max_rbr ? $obj->max_rbr + 1 : 1);
        }
        return 1;
    }

    /**
     * Get next Prilog sequential number for Akt
     */
    public static function getNextPrilogRbr($db, $akt_id)
    {
        $sql = "SELECT MAX(prilog_rbr) as max_rbr 
                FROM " . MAIN_DB_PREFIX . "a_akti_prilozi 
                WHERE fk_akt = " . (int)$akt_id;

        $resql = $db->query($sql);
        if ($resql && $obj = $db->fetch_object($resql)) {
            return ($obj->max_rbr ? $obj->max_rbr + 1 : 1);
        }
        return 1;
    }

    /**
     * Create new Akt
     */
    public static function createAkt($db, $predmet_id, $naziv_akta, $opis_akta, $ecmfile_id, $user_id, $entity)
    {
        try {
            $akt_rbr = self::getNextAktRbr($db, $predmet_id);
            
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "a_akti (
                        fk_predmet, akt_rbr, naziv_akta, opis_akta, fk_ecmfile, 
                        fk_user_kreirao, entity
                    ) VALUES (
                        " . (int)$predmet_id . ",
                        " . (int)$akt_rbr . ",
                        '" . $db->escape($naziv_akta) . "',
                        '" . $db->escape($opis_akta) . "',
                        " . (int)$ecmfile_id . ",
                        " . (int)$user_id . ",
                        " . (int)$entity . "
                    )";

            if ($db->query($sql)) {
                $akt_id = $db->last_insert_id(MAIN_DB_PREFIX . 'a_akti', 'ID_akta');
                dol_syslog("Created new Akt: ID=" . $akt_id . ", RBR=" . $akt_rbr, LOG_INFO);
                return [
                    'success' => true,
                    'akt_id' => $akt_id,
                    'akt_rbr' => $akt_rbr
                ];
            } else {
                throw new Exception("Database error: " . $db->lasterror());
            }
        } catch (Exception $e) {
            dol_syslog("Error creating Akt: " . $e->getMessage(), LOG_ERR);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create new Prilog for existing Akt
     */
    public static function createPrilog($db, $akt_id, $naziv_priloga, $opis_priloga, $ecmfile_id, $user_id, $entity)
    {
        try {
            $prilog_rbr = self::getNextPrilogRbr($db, $akt_id);
            
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "a_akti_prilozi (
                        fk_akt, prilog_rbr, naziv_priloga, opis_priloga, fk_ecmfile, 
                        fk_user_kreirao, entity
                    ) VALUES (
                        " . (int)$akt_id . ",
                        " . (int)$prilog_rbr . ",
                        '" . $db->escape($naziv_priloga) . "',
                        '" . $db->escape($opis_priloga) . "',
                        " . (int)$ecmfile_id . ",
                        " . (int)$user_id . ",
                        " . (int)$entity . "
                    )";

            if ($db->query($sql)) {
                $prilog_id = $db->last_insert_id(MAIN_DB_PREFIX . 'a_akti_prilozi', 'ID_priloga');
                dol_syslog("Created new Prilog: ID=" . $prilog_id . ", RBR=" . $prilog_rbr, LOG_INFO);
                return [
                    'success' => true,
                    'prilog_id' => $prilog_id,
                    'prilog_rbr' => $prilog_rbr
                ];
            } else {
                throw new Exception("Database error: " . $db->lasterror());
            }
        } catch (Exception $e) {
            dol_syslog("Error creating Prilog: " . $e->getMessage(), LOG_ERR);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get all Akti for predmet (for dropdown)
     */
    public static function getAktiForPredmet($db, $predmet_id, $entity)
    {
        $akti = [];
        
        $sql = "SELECT 
                    a.ID_akta,
                    a.akt_rbr,
                    a.naziv_akta,
                    a.opis_akta
                FROM " . MAIN_DB_PREFIX . "a_akti a
                WHERE a.fk_predmet = " . (int)$predmet_id . "
                AND a.entity = " . (int)$entity . "
                ORDER BY a.akt_rbr ASC";

        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $akti[] = [
                    'id' => $obj->ID_akta,
                    'rbr' => $obj->akt_rbr,
                    'naziv' => $obj->naziv_akta,
                    'opis' => $obj->opis_akta,
                    'display' => sprintf("AKT %02d - %s", $obj->akt_rbr, $obj->naziv_akta)
                ];
            }
        }
        
        return $akti;
    }

    /**
     * Get hierarchical document structure for predmet
     */
    public static function getHierarchicalDocuments($db, $conf, $predmet_id)
    {
        $documents = [];
        
        // Get relative path for predmet
        require_once __DIR__ . '/predmet_helper.class.php';
        $relative_path = Predmet_helper::getPredmetFolderPath($predmet_id, $db);
        
        // 1. Get all Akti with their documents
        $sql = "SELECT 
                    a.ID_akta,
                    a.akt_rbr,
                    a.naziv_akta,
                    a.opis_akta,
                    a.datum_kreiranja as akt_datum,
                    ef.filename as akt_filename,
                    ef.filepath as akt_filepath,
                    ef.date_c as akt_file_date,
                    ef.rowid as akt_ecm_id,
                    ef.digital_signature,
                    ef.signature_status,
                    ef.signer_name,
                    CONCAT(u.firstname, ' ', u.lastname) as akt_created_by
                FROM " . MAIN_DB_PREFIX . "a_akti a
                LEFT JOIN " . MAIN_DB_PREFIX . "ecm_files ef ON a.fk_ecmfile = ef.rowid
                LEFT JOIN " . MAIN_DB_PREFIX . "user u ON a.fk_user_kreirao = u.rowid
                WHERE a.fk_predmet = " . (int)$predmet_id . "
                AND a.entity = " . $conf->entity . "
                ORDER BY a.akt_rbr ASC";

        $resql = $db->query($sql);
        if ($resql) {
            while ($akt = $db->fetch_object($resql)) {
                // Add Akt as main document
                $akt_doc = new stdClass();
                $akt_doc->type = 'akt';
                $akt_doc->akt_id = $akt->ID_akta;
                $akt_doc->akt_rbr = $akt->akt_rbr;
                $akt_doc->display_number = sprintf("%02d", $akt->akt_rbr);
                $akt_doc->filename = $akt->akt_filename;
                $akt_doc->filepath = $akt->akt_filepath;
                $akt_doc->naziv = $akt->naziv_akta;
                $akt_doc->opis = $akt->opis_akta;
                $akt_doc->date_c = $akt->akt_file_date;
                $akt_doc->created_by = $akt->akt_created_by;
                $akt_doc->ecm_id = $akt->akt_ecm_id;
                $akt_doc->digital_signature = $akt->digital_signature;
                $akt_doc->signature_status = $akt->signature_status;
                $akt_doc->signer_name = $akt->signer_name;
                $akt_doc->indent_level = 0;
                
                $documents[] = $akt_doc;
                
                // 2. Get Prilozi for this Akt
                $sql_prilozi = "SELECT 
                                    p.ID_priloga,
                                    p.prilog_rbr,
                                    p.naziv_priloga,
                                    p.opis_priloga,
                                    p.datum_kreiranja as prilog_datum,
                                    ef.filename as prilog_filename,
                                    ef.filepath as prilog_filepath,
                                    ef.date_c as prilog_file_date,
                                    ef.rowid as prilog_ecm_id,
                                    ef.digital_signature,
                                    ef.signature_status,
                                    ef.signer_name,
                                    CONCAT(u.firstname, ' ', u.lastname) as prilog_created_by
                                FROM " . MAIN_DB_PREFIX . "a_akti_prilozi p
                                LEFT JOIN " . MAIN_DB_PREFIX . "ecm_files ef ON p.fk_ecmfile = ef.rowid
                                LEFT JOIN " . MAIN_DB_PREFIX . "user u ON p.fk_user_kreirao = u.rowid
                                WHERE p.fk_akt = " . (int)$akt->ID_akta . "
                                AND p.entity = " . $conf->entity . "
                                ORDER BY p.prilog_rbr ASC";

                $resql_prilozi = $db->query($sql_prilozi);
                if ($resql_prilozi) {
                    while ($prilog = $db->fetch_object($resql_prilozi)) {
                        $prilog_doc = new stdClass();
                        $prilog_doc->type = 'prilog';
                        $prilog_doc->akt_id = $akt->ID_akta;
                        $prilog_doc->akt_rbr = $akt->akt_rbr;
                        $prilog_doc->prilog_id = $prilog->ID_priloga;
                        $prilog_doc->prilog_rbr = $prilog->prilog_rbr;
                        $prilog_doc->display_number = sprintf("%02d-%02d", $akt->akt_rbr, $prilog->prilog_rbr);
                        $prilog_doc->filename = $prilog->prilog_filename;
                        $prilog_doc->filepath = $prilog->prilog_filepath;
                        $prilog_doc->naziv = $prilog->naziv_priloga;
                        $prilog_doc->opis = $prilog->opis_priloga;
                        $prilog_doc->date_c = $prilog->prilog_file_date;
                        $prilog_doc->created_by = $prilog->prilog_created_by;
                        $prilog_doc->ecm_id = $prilog->prilog_ecm_id;
                        $prilog_doc->digital_signature = $prilog->digital_signature;
                        $prilog_doc->signature_status = $prilog->signature_status;
                        $prilog_doc->signer_name = $prilog->signer_name;
                        $prilog_doc->indent_level = 1;
                        
                        $documents[] = $prilog_doc;
                    }
                }
            }
        }
        
        // 3. Get Nedodijeljena documents (ECM files not linked to any Akt or Prilog)
        $linked_ecm_ids = [];
        foreach ($documents as $doc) {
            if ($doc->ecm_id) {
                $linked_ecm_ids[] = $doc->ecm_id;
            }
        }
        
        $exclude_condition = '';
        if (!empty($linked_ecm_ids)) {
            $exclude_condition = " AND ef.rowid NOT IN (" . implode(',', $linked_ecm_ids) . ")";
        }
        
        $search_paths = [
            rtrim($relative_path, '/'),
            $relative_path
        ];
        
        $sql_conditions = [];
        foreach ($search_paths as $path) {
            $sql_conditions[] = "ef.filepath = '" . $db->escape($path) . "'";
        }
        
        $sql_nedodijeljena = "SELECT 
                                ef.rowid as ecm_id,
                                ef.filename,
                                ef.filepath,
                                ef.date_c,
                                ef.digital_signature,
                                ef.signature_status,
                                ef.signer_name,
                                CONCAT(u.firstname, ' ', u.lastname) as created_by
                            FROM " . MAIN_DB_PREFIX . "ecm_files ef
                            LEFT JOIN " . MAIN_DB_PREFIX . "user u ON ef.fk_user_c = u.rowid
                            WHERE (" . implode(' OR ', $sql_conditions) . ")
                            AND ef.entity = " . $conf->entity . "
                            " . $exclude_condition . "
                            ORDER BY ef.date_c DESC";

        $resql_nedodijeljena = $db->query($sql_nedodijeljena);
        $nedodijeljena_docs = [];
        if ($resql_nedodijeljena) {
            while ($nedodijeljeno = $db->fetch_object($resql_nedodijeljena)) {
                $nedodijeljeno_doc = new stdClass();
                $nedodijeljeno_doc->type = 'nedodijeljeno';
                $nedodijeljeno_doc->display_number = '-';
                $nedodijeljeno_doc->filename = $nedodijeljeno->filename;
                $nedodijeljeno_doc->filepath = $nedodijeljeno->filepath;
                $nedodijeljeno_doc->naziv = '';
                $nedodijeljeno_doc->opis = '';
                $nedodijeljeno_doc->date_c = $nedodijeljeno->date_c;
                $nedodijeljeno_doc->created_by = $nedodijeljeno->created_by;
                $nedodijeljeno_doc->ecm_id = $nedodijeljeno->ecm_id;
                $nedodijeljeno_doc->digital_signature = $nedodijeljeno->digital_signature;
                $nedodijeljeno_doc->signature_status = $nedodijeljeno->signature_status;
                $nedodijeljeno_doc->signer_name = $nedodijeljeno->signer_name;
                $nedodijeljeno_doc->indent_level = 1;
                
                $nedodijeljena_docs[] = $nedodijeljeno_doc;
            }
        }
        
        // Add Nedodijeljena section if there are unlinked documents
        if (!empty($nedodijeljena_docs)) {
            // Add header for Nedodijeljena
            $nedodijeljena_header = new stdClass();
            $nedodijeljena_header->type = 'nedodijeljeno_header';
            $nedodijeljena_header->display_number = '';
            $nedodijeljena_header->filename = 'NEDODIJELJENO';
            $nedodijeljena_header->naziv = 'Nedodijeljena dokumenta';
            $nedodijeljena_header->indent_level = 0;
            
            $documents[] = $nedodijeljena_header;
            
            // Add all nedodijeljena documents
            foreach ($nedodijeljena_docs as $doc) {
                $documents[] = $doc;
            }
        }
        
        return $documents;
    }

    /**
     * Delete Akt and all its Prilozi
     */
    public static function deleteAkt($db, $conf, $akt_id, $user_id)
    {
        try {
            $db->begin();
            
            // Get Akt details
            $sql = "SELECT fk_ecmfile FROM " . MAIN_DB_PREFIX . "a_akti WHERE ID_akta = " . (int)$akt_id;
            $resql = $db->query($sql);
            if (!$resql || !($akt = $db->fetch_object($resql))) {
                throw new Exception("Akt not found");
            }
            
            // Get all Prilozi for this Akt
            $sql = "SELECT fk_ecmfile FROM " . MAIN_DB_PREFIX . "a_akti_prilozi WHERE fk_akt = " . (int)$akt_id;
            $resql = $db->query($sql);
            $prilog_ecm_ids = [];
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $prilog_ecm_ids[] = $obj->fk_ecmfile;
                }
            }
            
            // Delete Prilozi records
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "a_akti_prilozi WHERE fk_akt = " . (int)$akt_id;
            if (!$db->query($sql)) {
                throw new Exception("Failed to delete Prilozi: " . $db->lasterror());
            }
            
            // Delete Akt record
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "a_akti WHERE ID_akta = " . (int)$akt_id;
            if (!$db->query($sql)) {
                throw new Exception("Failed to delete Akt: " . $db->lasterror());
            }
            
            // Delete ECM files (Akt + Prilozi)
            $all_ecm_ids = array_merge([$akt->fk_ecmfile], $prilog_ecm_ids);
            foreach ($all_ecm_ids as $ecm_id) {
                if ($ecm_id) {
                    // Delete physical file and ECM record
                    require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
                    $ecmfile = new EcmFiles($db);
                    if ($ecmfile->fetch($ecm_id) > 0) {
                        $ecmfile->delete($user_id);
                    }
                }
            }
            
            $db->commit();
            return ['success' => true, 'message' => 'Akt i svi prilozi su uspješno obrisani'];
            
        } catch (Exception $e) {
            $db->rollback();
            dol_syslog("Error deleting Akt: " . $e->getMessage(), LOG_ERR);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete single Prilog
     */
    public static function deletePrilog($db, $conf, $prilog_id, $user_id)
    {
        try {
            $db->begin();
            
            // Get Prilog details
            $sql = "SELECT fk_ecmfile FROM " . MAIN_DB_PREFIX . "a_akti_prilozi WHERE ID_priloga = " . (int)$prilog_id;
            $resql = $db->query($sql);
            if (!$resql || !($prilog = $db->fetch_object($resql))) {
                throw new Exception("Prilog not found");
            }
            
            // Delete Prilog record
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "a_akti_prilozi WHERE ID_priloga = " . (int)$prilog_id;
            if (!$db->query($sql)) {
                throw new Exception("Failed to delete Prilog: " . $db->lasterror());
            }
            
            // Delete ECM file
            if ($prilog->fk_ecmfile) {
                require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
                $ecmfile = new EcmFiles($db);
                if ($ecmfile->fetch($prilog->fk_ecmfile) > 0) {
                    $ecmfile->delete($user_id);
                }
            }
            
            $db->commit();
            return ['success' => true, 'message' => 'Prilog je uspješno obrisan'];
            
        } catch (Exception $e) {
            $db->rollback();
            dol_syslog("Error deleting Prilog: " . $e->getMessage(), LOG_ERR);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Move Nedodijeljeno document to Akt as Prilog
     */
    public static function moveToAkt($db, $conf, $ecm_id, $akt_id, $naziv_priloga, $opis_priloga, $user_id, $entity)
    {
        try {
            $db->begin();
            
            // Verify ECM file exists and is not already linked
            $sql = "SELECT ef.rowid FROM " . MAIN_DB_PREFIX . "ecm_files ef
                    LEFT JOIN " . MAIN_DB_PREFIX . "a_akti a ON ef.rowid = a.fk_ecmfile
                    LEFT JOIN " . MAIN_DB_PREFIX . "a_akti_prilozi p ON ef.rowid = p.fk_ecmfile
                    WHERE ef.rowid = " . (int)$ecm_id . "
                    AND a.fk_ecmfile IS NULL 
                    AND p.fk_ecmfile IS NULL";
            
            $resql = $db->query($sql);
            if (!$resql || $db->num_rows($resql) == 0) {
                throw new Exception("ECM file not found or already linked");
            }
            
            // Create Prilog
            $result = self::createPrilog($db, $akt_id, $naziv_priloga, $opis_priloga, $ecm_id, $user_id, $entity);
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            $db->commit();
            return [
                'success' => true, 
                'message' => 'Dokument je uspješno premješten u Akt kao Prilog',
                'prilog_rbr' => $result['prilog_rbr']
            ];
            
        } catch (Exception $e) {
            $db->rollback();
            dol_syslog("Error moving document to Akt: " . $e->getMessage(), LOG_ERR);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get document statistics for predmet
     */
    public static function getDocumentStatistics($db, $conf, $predmet_id)
    {
        $stats = [
            'total_akti' => 0,
            'total_prilozi' => 0,
            'total_nedodijeljena' => 0,
            'total_documents' => 0
        ];
        
        // Count Akti
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "a_akti 
                WHERE fk_predmet = " . (int)$predmet_id . " AND entity = " . $conf->entity;
        $resql = $db->query($sql);
        if ($resql && $obj = $db->fetch_object($resql)) {
            $stats['total_akti'] = (int)$obj->count;
        }
        
        // Count Prilozi
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "a_akti_prilozi p
                INNER JOIN " . MAIN_DB_PREFIX . "a_akti a ON p.fk_akt = a.ID_akta
                WHERE a.fk_predmet = " . (int)$predmet_id . " AND p.entity = " . $conf->entity;
        $resql = $db->query($sql);
        if ($resql && $obj = $db->fetch_object($resql)) {
            $stats['total_prilozi'] = (int)$obj->count;
        }
        
        // Count Nedodijeljena (this is more complex, need to get all ECM files and subtract linked ones)
        require_once __DIR__ . '/predmet_helper.class.php';
        $relative_path = Predmet_helper::getPredmetFolderPath($predmet_id, $db);
        
        $search_paths = [
            rtrim($relative_path, '/'),
            $relative_path
        ];
        
        $sql_conditions = [];
        foreach ($search_paths as $path) {
            $sql_conditions[] = "ef.filepath = '" . $db->escape($path) . "'";
        }
        
        // Get all ECM files for predmet
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "ecm_files ef
                WHERE (" . implode(' OR ', $sql_conditions) . ") AND ef.entity = " . $conf->entity;
        $resql = $db->query($sql);
        $total_ecm = 0;
        if ($resql && $obj = $db->fetch_object($resql)) {
            $total_ecm = (int)$obj->count;
        }
        
        $stats['total_nedodijeljena'] = $total_ecm - $stats['total_akti'] - $stats['total_prilozi'];
        $stats['total_documents'] = $total_ecm;
        
        return $stats;
    }
}