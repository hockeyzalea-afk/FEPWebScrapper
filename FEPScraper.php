
<?php

require_once 'simple_html_dom.php';

class Scraper_result {
    public $teams;
    public $partidos;
    public $jugadores;
    public $estadisticas;

    public function __construct() {
        $this->teams = [];
        $this->partidos = [];
        $this->jugadores = [];
        $this->estadisticas = [];
    }

    public function addTeams($teams) {
        foreach ($teams as $team) {
            $this->teams[] = $team;
        }
    }

    public function addPartido($partido) {
        $this->partidos[] = $partido;
    }

    public function addJugadores($lista) {
        foreach ($lista as $jugador) {
            $this->addJugador($jugador);
        }
    }

    public function addEstadisticas($lista) {
        foreach ($lista as $estadistica) {
            $this->addEstadistica($estadistica);
        }
    }

    public function addJugador($jugador) {
        // Ignore entries without an id
        if (!isset($jugador['id']) || $jugador['id'] === '') {
            return;
        }

        $id = (string) $jugador['id'];

        // If the jugador already exists, do not overwrite
        if (isset($this->jugadores[$id])) {
            return;
        }

        $this->jugadores[$id] = $jugador;
    }

    public function addEstadistica($estadistica) {
        $this->estadisticas[] = $estadistica;
    }

    public function getTeams() {
        return ($this->teams);
    }

    public function getPartidos() { 
        return ($this->partidos);
    }
    public function getJugadores() {
        return ($this->jugadores);
    }
    public function getEstadisticas() {
        return $this->estadisticas;
    }

    public function createResultArray () {
        $result = "{'teams': " . ($this->teams) . ",
                      'partidos': " . ($this->partidos) . ",
                      'jugadores': " . ($this->jugadores) . ",
                      'estadisticas': " . ($this->estadisticas) . "}";
        return $result;
    }
    
}


class FEPScraper {
    private $league_id;
    private $url_clasificacion;
    private $url_calendario;
    private $url_jugadores;
    private $url_detail_partido1;
    private $url_detail_partido2;
    private $soup_class;
    private $soup_jug;
    private $soup_par;
    private $lock_file;
    private $is_locked = false;
    
    
    public function __construct($league_id) {
        $this->league_id = $league_id;
        $this->url_clasificacion = "https://www.server2.sidgad.es/rfep/rfep_clasif_idc_".$league_id."_1.php";
        $this->url_calendario = "https://www.server2.sidgad.es/rfep/rfep_cal_idc_".$league_id."_1.php";
        $this->url_jugadores = "https://www.server2.sidgad.es/rfep/rfep_stats_1_".$league_id.".php";
        $this->url_detail_partido1 = "https://www.server2.sidgad.es/rfep/rfep_gr_";
        $this->url_detail_partido2 = "_1.php";
        
        $this->resultado = new Scraper_result();
        // Archivo de lock basado en el league_id para evitar conflictos
        $this->lock_file = sys_get_temp_dir() . '/fep_scraper_' . md5($league_id) . '.lock';
    }
    
    /**
     * Adquiere el lock para evitar ejecuciones simultáneas
     * @return bool True si adquirió el lock, false si ya está en ejecución
     */
    private function acquireLock() {
        // Si ya tenemos el lock, devolvemos true
        if ($this->is_locked) {
            return true;
        }
        
        // Verificar si el lock existe y no ha expirado (timeout de 30 minutos)
        if (file_exists($this->lock_file)) {
            $lock_time = filemtime($this->lock_file);
            $current_time = time();
            
            // Si el lock tiene más de 30 minutos, lo consideramos obsoleto
            if (($current_time - $lock_time) < 1800) { // 1800 segundos = 30 minutos
                return false;
            } else {
                // Lock obsoleto, lo eliminamos
                @unlink($this->lock_file);
            }
        }
        
        // Intentar crear el archivo de lock
        $handle = @fopen($this->lock_file, 'x');
        if ($handle === false) {
            return false;
        }
        
        fclose($handle);
        
        // Escribir información en el archivo de lock
        $lock_info = [
            'pid' => getmypid(),
            'league_id' => $this->league_id,
            'start_time' => date('Y-m-d H:i:s'),
            'timeout' => 1800 // 30 minutos en segundos
        ];
        
        file_put_contents($this->lock_file, json_encode($lock_info));
        $this->is_locked = true;
        
        // Registrar función de limpieza al finalizar
        register_shutdown_function([$this, 'releaseLock']);
        
        return true;
    }
    
    /**
     * Libera el lock
     */
    public function releaseLock() {
        if ($this->is_locked && file_exists($this->lock_file)) {
            @unlink($this->lock_file);
            $this->is_locked = false;
        }
    }
    
    /**
     * Verifica si el proceso está en ejecución
     * @return bool True si está en ejecución, false si no
     */
    public function isRunning() {
        if (!file_exists($this->lock_file)) {
            return false;
        }
        
        $lock_time = filemtime($this->lock_file);
        $current_time = time();
        
        // Si el lock tiene más de 30 minutos, lo consideramos obsoleto
        if (($current_time - $lock_time) >= 1800) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Obtiene información del lock actual
     * @return array|null Información del lock o null si no existe
     */
    public function getLockInfo() {
        if (!file_exists($this->lock_file)) {
            return null;
        }
        
        $content = @file_get_contents($this->lock_file);
        if ($content === false) {
            return null;
        }
        
        $info = json_decode($content, true);
        if (!$info) {
            return null;
        }
        
        $info['lock_file'] = $this->lock_file;
        $info['lock_age'] = time() - filemtime($this->lock_file);
        $info['is_obsolete'] = $info['lock_age'] >= 1800;
        
        return $info;
    }
    
    /**
     * Forzar la liberación del lock (para administración)
     * @return bool True si se liberó, false si no
     */
    public function forceReleaseLock() {
        if (file_exists($this->lock_file)) {
            $result = @unlink($this->lock_file);
            $this->is_locked = false;
            return $result;
        }
        return true;
    }


    
    /**
     * Ejecuta el scraping con protección contra ejecuciones simultáneas
     * @param bool $force Forzar ejecución incluso si ya está en ejecución (liberando el lock anterior)
     * @return array|null Resultados del scraping o null si no se pudo ejecutar
     * @throws Exception Si no se puede adquirir el lock
     */
    public function run($force = false) {
        //$resultado = new Scraper_result();
        // Verificar si ya estamos corriendo
       /* if ($this->isRunning()) {
            if ($force) {
                // Forzar liberación del lock anterior
                $this->forceReleaseLock();
            } else {
                throw new Exception("El scraping ya está en ejecución para la liga " . $this->league_id);
            }
        }
        
        // Adquirir lock
        if (!$this->acquireLock()) {
            throw new Exception("No se pudo adquirir el lock para ejecutar el scraping");
        }*/
        
        try {
            $partidos = $this->get_Partidos();
            $count_partidos = count($partidos);
            foreach ($partidos as $partido) {
                //$partido = $partidos['partidos'][0];
                if ($partido['id'] == '') {
                     $this->resultado->addPartido($partido);
                    //throw new Exception("No se pudo obtener el ID del primer partido para detalle");
                } else {
                    $jornada = explode(" ", trim($partido['jornada']));
                    $jornada_str = $jornada[1] ?? '0';
                    $jornada_int = intval($jornada_str);
                    $detalle_partido = $this->get_Detalle_Partido($partido['id'], $partido['local_id'], $partido['visit_id'],$jornada_int);
                    
                    $jugadores = $detalle_partido['jugadores']; 
                    $estadisticas = $detalle_partido['estadisticas'];
                    $this->resultado->addPartido($partido);
                    $this->resultado->addJugadores($jugadores);
                    $this->resultado->addEstadisticas($estadisticas);
                }
            }
            $teams = $this->getTeams();
            $this->resultado->addTeams($teams);
            // Liberar lock al finalizar
            $this->releaseLock();
            
        } catch (Exception $e) {
            // Asegurarse de liberar el lock en caso de error
            $this->releaseLock();
            echo "Error: " . $e->getMessage() . "\n";
            //throw new Exception("Error durante el scraping: " . $e->getMessage());
        }
        $data =[
            'competicion' => $this->league_id,
            'teams' => $this->resultado->getTeams(),
            'team_count' => count($this->resultado->getTeams()),
            'partidos' => $this->resultado->getPartidos(),
            'partidos_count' => count($this->resultado->getPartidos()),
            'jugadores' => $this->resultado->getJugadores(),
            'jugadores_count' => count($this->resultado->getJugadores()),
            'estadisticas' => $this->resultado->getEstadisticas(),
            'estadisticas_count' => count($this->resultado->getEstadisticas())
        ];
        return json_encode([
            'status' => 'ok',
            'data' => $data
        ]);
        //print_r((json_encode($partidos)));
        //$str = json_decode($partidos, true);
        // return $str;
        //return ($partidos);
    }


    
    /**
     * Ejecuta el scraping en segundo plano (asincrónico)
     * @param callable $callback Función a llamar cuando termine (recibe los resultados o error)
     * @param bool $force Forzar ejecución
     */
    public function runAsync($callback) {
        // Verificar si ya estamos corriendo
        if ($this->isRunning()) {
            throw new Exception("El scraping ya está en ejecución para la liga " . $this->league_id);
        }
        
        // Adquirir lock
        if (!$this->acquireLock()) {
            throw new Exception("No se pudo adquirir el lock para ejecutar el scraping");
        }
        
        // Ejecutar en segundo plano
        $pid = pcntl_fork();
        if ($pid == -1) {
            // Error al crear el proceso hijo
            $this->releaseLock();
            throw new Exception("No se pudo crear el proceso hijo para el scraping");
        } elseif ($pid) {
            // Proceso padre
            return;
        } else {
            // Proceso hijo
            try {
                $result = $this->run();
                call_user_func($callback, $result, null);
            } catch (Exception $e) {
                call_user_func($callback, null, $e);
            } finally {
                // Asegurarse de liberar el lock
                $this->releaseLock();
                exit(0);
            }
        }
    }


    private function fetchHTML($url, $data, $headers) {
        try {
            $ch = curl_init();

            // Set cURL options
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute cURL session
            $response = curl_exec($ch);

            // Check for cURL errors
            if ($response === false) {
                throw new Exception('Error occurred while fetching the data: ' 
                    . curl_error($ch));
            }

            // Close cURL session
            curl_close($ch);

            return $response;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /*  EQUIPOS  */
    private function parseTeams($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $finder = new DomXPath($dom);

            $tables = $finder->query("//table[contains(@class, 'tabla_clasif')]");
            $tables_count   = 0;
            $teams_count = 0;
            foreach ($tables as $table) {
                $rows = $finder->query(".//tr", $table);
                foreach ($rows as $row) {
                    $team_data = $this->extract_team_from_row($row);
                    if ($team_data) {
                        $id = $this->getTeamIdfromPartidos($this->resultado->getPartidos(), $team_data['name']);
                        $team_data['id'] = $id;
                        $team_data['competicion_id'] = $this->league_id;
                        $teams[$team_data['id']] = $team_data;
                    }
                    $teams_count++;
                }
                $tables_count++;

            }
            return $teams;
    }
    
    private function extract_team_from_row($row) {
        try {
            $xpath = new DOMXPath($row->ownerDocument);
            $cells = $xpath->query(".//td", $row);
            
            if ($cells->length == 12) {
                $img_cell = $xpath->query(".//img", $cells->item(1))->item(0);
                $img = $img_cell ? $img_cell->getAttribute('src') : '';
                
                $team_name_cell = $cells->item(2);
                $no_mobile = $xpath->query(".//*[contains(@class, 'no_mobile')]", $team_name_cell)->item(0);
                $mobile = $xpath->query(".//*[contains(@class, 'mobile')]", $team_name_cell)->item(0);
                
                $team_name = trim($no_mobile->textContent);
                $team_shortname = trim($mobile->textContent);
                $puntos = trim($cells->item(3)->textContent);
                
                $pj_cell = $xpath->query(".//div", $cells->item(4))->item(0);
                $pj = trim($pj_cell->textContent);
                
                $pg_cell = $xpath->query(".//div", $cells->item(5))->item(0);
                $pg = trim($pg_cell->textContent);
                
                $pe_cell = $xpath->query(".//div", $cells->item(6))->item(0);
                $pe = trim($pe_cell->textContent);
                
                $pp_cell = $xpath->query(".//div", $cells->item(7))->item(0);
                $pp = trim($pp_cell->textContent);
                
                $gf_cell = $xpath->query(".//div", $cells->item(8))->item(0);
                $gf = trim($gf_cell->textContent);
                
                $gc_cell = $xpath->query(".//div", $cells->item(9))->item(0);
                $gc = trim($gc_cell->textContent);
                
                $gav_cell = $xpath->query(".//div", $cells->item(10))->item(0);
                $gav = trim($gav_cell->textContent);
                
                return [
                    'id' => '',
                    'name' => $team_name,
                    'shortname' => $team_shortname,
                    'puntos' => $puntos,
                    'pj' => $pj,
                    'pg' => $pg,
                    'pe' => $pe,
                    'pp' => $pp,
                    'gf' => $gf,
                    'gc' => $gc,
                    'gav' => $gav,
                    'logo' => $img
                ];
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            error_log("Error extrayendo equipo de fila: " . $e->getMessage());
        }
        return null;
    }

    public function getTeams() {
        $url = $this->url_clasificacion;
        $data = ['idc' => $this->league_id, 'site_lang' => 'es'];

        $headers = [
        'Accept: text/html, */*; q=0.01',
        'Accept-Language: es-ES,es;q=0.8,en-US;q=0.5,en;q=0.3',
        'Accept-Encoding: gzip, deflate',
        'Content-Type: text/html;charset=UTF-8',
        'Content-Length: 21',
        'Origin: https://www.hockeypatines.fep.es',
        'Referer: https://www.hockeypatines.fep.es/',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: cross-site',
        'Priority: u=0'
        ];
        
        // Initialize cURL session
        try {

            $response  = $this->fetchHTML($url, $data, $headers);
            $dom = $this->parseTeams($response);


        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            throw $e;
        }
        return $dom;
    }

    /*  PARTIDOS  */



    private function parsePartidos($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DomXPath($dom);
        
        try {
            $tables = $xpath->query("//table[@id='my_calendar_table']");
            $table = $tables->item(0);
            $tbodys = $xpath->query(".//tbody", $table);
            $tbodys_count   = 1;
            foreach ($tbodys as $tbody) {
            
                $rows = $xpath->query(".//tr", $tbody);
                
                foreach ($rows as $row) {
                    $team_data = $this->extract_partido_from_row($row);
                    if ($team_data) {
                        $team_data['competicion_id'] = $this->league_id;
                        $partidos[] = $team_data;
                    }
                }
                $tbodys_count++;
            }
            
            $resultado = [
                'partidos' => $partidos/*,
                'jugadores' => $jugadores,
                'estadisticas' => $estadisticas*/
            ];
            return $partidos;
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            throw $e;
        }

            return $teams;
    }

    private function extract_partido_from_row($row) {
        try {
            $tr_class = $row->getAttribute('class');
            $class_parts = explode(' ', $tr_class);
            $local_class = $class_parts[0];
            $visit_class = $class_parts[1];
            
            $local_parts = explode('_', $local_class);
            $visit_parts = explode('_', $visit_class);
            $local_id = $local_parts[1];
            $visit_id = $visit_parts[1];
            
            $xpath = new DOMXPath($row->ownerDocument);
            $cells = $xpath->query(".//td", $row);
            
            if ($cells->length == 16) {
                $fecha_cell = $xpath->query(".//div", $cells->item(1))->item(0);
                $fecha = $fecha_cell ? trim($fecha_cell->textContent) : '';
                
                $hora = trim($cells->item(2)->textContent);
                $jornada = trim($cells->item(4)->textContent);
                
                $local_cell = $cells->item(6);
                $local_div = $xpath->query(".//div", $local_cell)->item(0);
                $local = $local_div ? trim($local_div->textContent) : '';
                
                $visit_cell = $cells->item(8);
                $visit_div = $xpath->query(".//div", $visit_cell)->item(0);
                $visit = $visit_div ? trim($visit_div->textContent) : '';
                
                $resultado = trim($cells->item(11)->textContent);
                
                $link_cell = $cells->item(14);
                $link_is = $xpath->query(".//i", $link_cell);
                $id = $link_is->length > 0 ? $link_is->item(0)->getAttribute('idp') : '';
                
                $video = '';
                $video_cell = $cells->item(15);
                $link_vs = $xpath->query(".//a", $video_cell);
                if ($link_vs->length > 0) {
                    $link_v = $link_vs->item(0);
                    $video = $link_v ? $link_v->getAttribute('url_video') : '';
                }
                
                return [
                    'id' => $id,
                    'fecha' => $fecha,
                    'hora' => $hora,
                    'jornada' => $jornada,
                    'local' => $local,
                    'local_id' => $local_id,
                    'visit' => $visit,
                    'visit_id' => $visit_id,
                    'resultado' => $resultado,
                    'video' => $video
                ];
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            error_log("Error extrayendo partido de fila: " . $e->getMessage());
            throw $e;
        }
        return null;
    }

    private function get_Partidos() {
        $partidos = [];
        $jugadores = [];
        $estadisticas = [];

        $url = $this->url_calendario;
        $data = ['idc' => $this->league_id, 'site_lang' => 'es'];
        
            
            $headers = [
                'Accept: text/html, */*; q=0.01',
                'Accept-Language: es-ES,es;q=0.8,en-US;q=0.5,en;q=0.3',
                'Accept-Encoding: gzip, deflate',
                'Content-Type: text/html;charset=UTF-8',
                'Content-Length: 21',
                'Origin: https://www.hockeypatines.fep.es',
                'Connection: keep-alive',
                'Referer: https://www.hockeypatines.fep.es/',
                'Sec-Fetch-Dest: empty',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: cross-site',
                'Priority: u=0'
            ];
        // Initialize cURL session
        try {

            
            $response  = $this->fetchHTML($url, $data, $headers);
            $json = json_decode($html, true);
            //echo $json;
            $dom = $this->parsePartidos($response);
            //echo $dom;

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            throw $e;
        }
        return $dom;
    }
    /*
    private function parsear_partido($soup, $partido_id, $local_id, $visit_id) {
        try {
            $xpath = new DOMXPath($soup);
            $tables = $xpath->query("//table[contains(@class, 'competiciones_tabla_basic')]");
            

            $jugadores = [];
            $estadisticas = [];
            $column_tarjeta_amarilla = -1;
            $column_tarjeta_azul = -1;
            $column_tarjeta_roja = -1;
            $tabla_local = $tables->item(0);
            $tabla_visit = $tables->item(1);
            $cabecera = $xpath->query(".//thead", $tabla_local)->item(0);
            
            
            
            
            
            
            
            
            
            $cabecera_rows = $xpath->query(".//tr", $cabecera);
            $cabecera_row = $cabecera_rows ->item(1);


            $cabecera_cells = $xpath->query(".//th", $cabecera_row);
            $num_columns = $cabecera_cells->length;
            if ($num_columns == 14) {
                //$column_tarjeta_amarilla = 12;
                $column_tarjeta_azul = 12;
                $column_tarjeta_roja = 13;
            } elseif ($num_columns == 15) {



                $incidencias = $xpath->query(".//td[contains(@width,'25') and position() >= último-th-sin-incidencias]", $cabecera_row); 
                // Ajusta el position() según cuántas columnas haya antes

                foreach ($incidencias as $i => $celda) {
                    $div = $xpath->query(".//div", $celda)->item(0);
                    if (!$div) continue;

                    $clase = $div->getAttribute('class');
                    $style = $div->getAttribute('style');

                    if (strpos($clase, 'game_view_incidencias_azul') !== false) {
                        // → Tarjeta AZUL (2 minutos)
                            $column_tarjeta_azul= $i;
                    } 
                    elseif (strpos($clase, 'game_view_incidencias_roja') !== false) {
                        if (strpos($style, '#FFCC00') !== false) {
                            // → Roja por acumulación / reincidencia
                            $column_tarjeta_roja = $i;
                        } else {
                            // → Roja directa
                            $column_tarjeta_amarilla = $i;
                        }
                    }
                }
            } 
            // Procesar local
            $tbody = $xpath->query(".//tbody", $tabla_local)->item(0);
            $rows = $xpath->query(".//tr", $tbody);
            
            foreach ($rows as $row) {
                $player_data = $this->extract_player_estadistics_from_row($row, $partido_id, $local_id/*, $column_tarjeta_amarilla, $column_tarjeta_azul, $column_tarjeta_roja);
                if ($player_data) {
                    $jugadores[] = $player_data['player'];
                    $estadisticas[] = $player_data['datos_partido'];
                }
            }
            
            // Procesar visitante
            $tbody = $xpath->query(".//tbody", $tabla_visit)->item(0);
            $rows = $xpath->query(".//tr", $tbody);
            
            foreach ($rows as $row) {
                $player_data = $this->extract_player_estadistics_from_row($row, $partido_id, $visit_id);
                if ($player_data) {
                    $jugadores[] = $player_data['player'];
                    $estadisticas[] = $player_data['datos_partido'];
                }
            }
            
            $data = [
                'jugadores' => $jugadores,
                'estadisticas' => $estadisticas
            ];
            return $data;
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            throw $e;
        }
    }*/

    /*  DETALLE PARTIDO  */ 

    private function getTeamIdfromPartidos($partidos, $team_name) {
        foreach ($partidos as $partido) {
            if ($partido['local'] == $team_name) {
                return $partido['local_id'];
            }
            if ($partido['visit'] == $team_name) {
                return $partido['visit_id'];
            }
        }
        return null;
    }
    
    private function extract_last_name_from_fullname($full_name, $name) {
        if (strpos($full_name, $name) !== false) {
            $x = strpos($full_name, $name);
            $last_name = substr($full_name, 0, $x);
            return trim($last_name);
        }
        return $full_name;
    }
    private function extract_player_estadistics_from_row2($row, $partido_id, $equipo_id, $jornada) {
        try {
            $xpath = new DOMXPath($row->ownerDocument);
            $cells = $xpath->query(".//td", $row);
            
            if ($cells->length == 15 || $cells->length == 14) {
                $player_number = trim($cells->item(0)->textContent);
                $isP_cell = $cells->item(1);
                $div = $xpath->query(".//div", $isP_cell)->item(0);
                $isP_text = trim($div->textContent);
                $isP = $isP_text == 'P';
                $posicion = $isP ? 'Portero' : 'Jugador';
                
                $player_name_cell = $cells->item(5);
                $div = $xpath->query(".//div", $player_name_cell)->item(0);
                $player_id = $div->getAttribute('id_player');
                $player_last_name = trim($div->textContent);
                $span = $xpath->query(".//span", $div)->item(0);
                $player_name = trim($span->textContent);
                
                $last = $this->extract_last_name_from_fullname($player_last_name, $player_name);
                $goles = intval(trim($cells->item(6)->textContent)) ?: 0;
                $asistencias = intval(trim($cells->item(7)->textContent)) ?: 0;
                if ($cells->length == 14) {
                    $amarillas = 0;
                    $azules = intval(trim($cells->item(12)->textContent)) ?: 0;
                    $rojas = intval(trim($cells->item(13)->textContent)) ?: 0;
                } else {
                    if ($jornada >= 7) {
                        $column_tarjeta_amarilla = 12;
                        $column_tarjeta_azul = 13;
                        $column_tarjeta_roja = 14;
                    } else {
                        $column_tarjeta_amarilla = 14;
                        $column_tarjeta_azul = 13;
                        $column_tarjeta_roja = 12;
                    }
                    $amarillas = intval(trim($cells->item($column_tarjeta_amarilla)->textContent)) ?: 0;
                    $azules = intval(trim($cells->item($column_tarjeta_azul)->textContent)) ?: 0;
                    $rojas = intval(trim($cells->item($column_tarjeta_roja)->textContent)) ?: 0;
                }
                /*$amarillas = intval(trim($cells->item(12)->textContent)) ?: 0;
                $azules = intval(trim($cells->item(13)->textContent)) ?: 0;
                $rojas = intval(trim($cells->item(14)->textContent)) ?: 0;*/
                
                $player = [
                    'id' => intval($player_id),
                    'nombre' => $player_name,
                    'apellidos' => $last,
                    'fecha_nacimiento' => '',
                    'dorsal' => intval($player_number),
                    'posicion' => $posicion,
                    'equipo_id' => intval($equipo_id),
                    'telefono' => '',
                    'email' => '',
                    'foto' => ''
                ];
                
                $estadistica = [
                    'partido_id' => intval($partido_id),
                    'jugador_id' => intval($player_id),
                    'equipo_id' => intval($equipo_id),
                    'minutos_jugados' => '',
                    'goles' => $goles,
                    'asistencias' => $asistencias,
                    'tarjetas_amarillas' => $amarillas,
                    'tarjetas_azules' => $azules,
                    'tarjetas_rojas' => $rojas
                ];
                
                return ['player' => $player, 'datos_partido' => $estadistica];
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            error_log("Error extrayendo jugador de fila: " . $e->getMessage());
        }
        return null;
    }
    private function extract_player_estadistics_from_row($row, $partido_id, $equipo_id/*, $column_tarjeta_amarilla, $column_tarjeta_azul, $column_tarjeta_roja*/) {
        try {
            $xpath = new DOMXPath($row->ownerDocument);
            $cells = $xpath->query(".//td", $row);
            
            if ($cells->length == 15 || $cells->length == 14) {
                $player_number = trim($cells->item(0)->textContent);
                $isP_cell = $cells->item(1);
                $div = $xpath->query(".//div", $isP_cell)->item(0);
                $isP_text = trim($div->textContent);
                $isP = $isP_text == 'P';
                $posicion = $isP ? 'Portero' : 'Jugador';
                
                $player_name_cell = $cells->item(5);
                $div = $xpath->query(".//div", $player_name_cell)->item(0);
                $player_id = $div->getAttribute('id_player');
                $player_last_name = trim($div->textContent);
                $span = $xpath->query(".//span", $div)->item(0);
                $player_name = trim($span->textContent);
                
                $last = $this->extract_last_name_from_fullname($player_last_name, $player_name);
                $goles = intval(trim($cells->item(6)->textContent)) ?: 0;
                $asistencias = intval(trim($cells->item(7)->textContent)) ?: 0;
                /*if ($cells->length == 14) {
                    $amarillas = 0;
                    $azules = intval(trim($cells->item(12)->textContent)) ?: 0;
                    $rojas = intval(trim($cells->item(13)->textContent)) ?: 0;
                } else {
                    $amarillas = intval(trim($cells->item($column_tarjeta_amarilla)->textContent)) ?: 0;
                    $azules = intval(trim($cells->item($column_tarjeta_azul)->textContent)) ?: 0;
                    $rojas = intval(trim($cells->item($column_tarjeta_roja)->textContent)) ?: 0;
                }*/
                $amarillas = intval(trim($cells->item(12)->textContent)) ?: 0;
                $azules = intval(trim($cells->item(13)->textContent)) ?: 0;
                $rojas = intval(trim($cells->item(14)->textContent)) ?: 0;
                
                $player = [
                    'id' => intval($player_id),
                    'nombre' => $player_name,
                    'apellidos' => $last,
                    'fecha_nacimiento' => '',
                    'dorsal' => intval($player_number),
                    'posicion' => $posicion,
                    'equipo_id' => intval($equipo_id),
                    'telefono' => '',
                    'email' => '',
                    'foto' => ''
                ];
                
                $estadistica = [
                    'partido_id' => intval($partido_id),
                    'jugador_id' => intval($player_id),
                    'equipo_id' => intval($equipo_id),
                    'minutos_jugados' => '',
                    'goles' => $goles,
                    'asistencias' => $asistencias,
                    'tarjetas_amarillas' => $amarillas,
                    'tarjetas_azules' => $azules,
                    'tarjetas_rojas' => $rojas
                ];
                
                return ['player' => $player, 'datos_partido' => $estadistica];
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            error_log("Error extrayendo jugador de fila: " . $e->getMessage());
        }
        return null;
    }

    private function parseDetallePartido($html, $partido_id, $local_id, $visit_id,$jornada) {
        try {
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DomXPath($dom);
            $tables = $xpath->query("//table[contains(@class, 'competiciones_tabla_basic')]");
            
            $jugadores = [];
            $estadisticas = [];
            
            $tabla_local = $tables->item(0);
            $tabla_visit = $tables->item(1);
            
            // Procesar local
            $tbody = $xpath->query(".//tbody", $tabla_local)->item(0);
            $rows = $xpath->query(".//tr", $tbody);
            
            foreach ($rows as $row) {
                $player_data = $this->extract_player_estadistics_from_row($row, $partido_id, $local_id);
                if ($player_data) {
                    $jugadores[] = $player_data['player'];
                    $estadisticas[] = $player_data['datos_partido'];
                }
            }
            
            // Procesar visitante
            $tbody = $xpath->query(".//tbody", $tabla_visit)->item(0);
            $rows = $xpath->query(".//tr", $tbody);
            
            foreach ($rows as $row) {
                $player_data = $this->extract_player_estadistics_from_row($row, $partido_id, $visit_id);
                if ($player_data) {
                    $jugadores[] = $player_data['player'];
                    $estadisticas[] = $player_data['datos_partido'];
                }
            }
            
            $data = [
                'jugadores' => $jugadores,
                'estadisticas' => $estadisticas
            ];
            return $data;
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            throw  $e;
        }
    }

    private function get_Detalle_Partido($par_id, $local_id, $visit_id,$jornada) {
        $partidos = [];
        $jugadores = [];
        $estadisticas = [];

        $url = $this->url_detail_partido1. $par_id . $this->url_detail_partido2;
        $data = ['idc' => $this->league_id,'idp' => $par_id, 'tab' => 'tab_ficha_resumen', 'idm' => '1'];

            
        $headers = [
            'Accept: text/html, */*; q=0.01',
            'Accept-Language: es-ES,es;q=0.8,en-US;q=0.5,en;q=0.3',
            'Accept-Encoding: gzip, deflate',
            'connection:keep-alive',
            'content-length:43',
            'content-type:application/x-www-form-urlencoded; charset=UTF-8',
            'host:www.server2.sidgad.es',
            'origin:https://www.hockeypatines.fep.es',
            'referer:https://www.hockeypatines.fep.es/',
            'sec-ch-ua:"Chromium";v="142", "Google Chrome";v="142", "Not_A Brand";v="99"',
            'sec-ch-ua-mobile:?0',
            'sec-ch-ua-platform:Linux',
            'sec-fetch-dest:empty',
            'sec-fetch-mode:cors',
            'sec-fetch-site:cross-site',
            'user-agent:Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'
        ];
        // Initialize cURL session
        try {

            
            $response  = $this->fetchHTML($url, $data, $headers);
            $json = json_decode($html, true);
            $dom = $this->parseDetallePartido($response, $par_id, $local_id, $visit_id,$jornada);
            $jugadores = $dom['jugadores']; 
            $estadisticas = $dom['estadisticas'];


        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            throw $e;
        }
        return $dom;
    }
    /*  JUGADORES  */
    private function get_Jugadores() {
        $partidos = [];
        $jugadores = [];
        $estadisticas = [];

        $url = $this->url_jugadores;
        $data = ['idc' => $this->league_id, 'site_lang' => 'es', 'tipo_stats' => 'plantillas'];
        
            
        $headers = [
            'accept:text/html, */*; q=0.01',
            'accept-encoding:gzip, deflate, br, zstd',
            'accept-language:es-ES,es;q=0.9',
            'connection:keep-alive',
            'content-length:43',
            'content-type:application/x-www-form-urlencoded; charset=UTF-8',
            'host:www.server2.sidgad.es',
            'origin:https://www.hockeypatines.fep.es',
            'referer:https://www.hockeypatines.fep.es/',
            'sec-ch-ua:"Chromium";v="142", "Google Chrome";v="142", "Not_A Brand";v="99"',
            'sec-ch-ua-mobile:?0',
            'sec-ch-ua-platform:Linux',
            'sec-fetch-dest:empty',
            'sec-fetch-mode:cors',
            'sec-fetch-site:cross-site',
            'user-agent:Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'
        ];
        // Initialize cURL session
        try {

            
            $response  = $this->fetchHTML($url, $data, $headers);
            //echo "response: " . print_r($response, true) . "\n";
            $json = json_decode($html, true);
            //echo "json: " . $json . "\n";
            $dom = $this->parseJugadores($response);
            //echo "******* dom: " . print_r($dom, true) . "\n";


        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
        //echo $dom;
        //echo "finish get_Jugadores...\n";
        return $dom;

    }

    private function parseJugadores() {
        try {
            $xpath = new DOMXPath($row->ownerDocument);
            $cells = $xpath->query(".//td", $row);
            
            if ($cells->length >= 10) {
                $player_name_cell = $cells->item(1);
                $player_div = $xpath->query(".//div", $player_name_cell)->item(0);
                $player_name = $player_div ? trim($player_div->textContent) : '';
                
                $player_id = '';
                $player_link = $xpath->query(".//a", $player_name_cell)->item(0);
                if ($player_link) {
                    $href = $player_link->getAttribute('href');
                    parse_str(parse_url($href, PHP_URL_QUERY), $query_params);
                    if (isset($query_params['idj'])) {
                        $player_id = $query_params['idj'];
                    }
                }
                
                // Extraer estadísticas (ejemplo simple)
                $goles = trim($cells->item(3)->textContent);
                $asistencias = trim($cells->item(4)->textContent);
                
                $player = [
                    'id' => $player_id,
                    'name' => $player_name,
                    'team_id' => $team_id
                ];
                
                $datos_partido = [
                    'partido_id' => $partido_id,
                    'team_id' => $team_id,
                    'player_id' => $player_id,
                    'goles' => $goles,
                    'asistencias' => $asistencias
                ];
                
                return [
                    'player' => $player,
                    'datos_partido' => $datos_partido
                ];
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            error_log("Error extrayendo estadísticas de jugador de fila: " . $e->getMessage());
        }
        return null;
    }

    private function createResultArray($teams, $partidos, $jugadores, $estadisticas) {
        
        
        
        return [
            'teams' => $teams,
            'partidos' => $partidos,
            'jugadores' => $jugadores,
            'estadisticas' => $estadisticas
        ];
    }


}

// Only run the scraper when this file is executed directly (not when included)
$force = (isset($_GET['force']) && $_GET['force'] === '1');
$competitionId = $_GET['competitionId'];

$req  = "". $competitionId;
$scraper = new FEPScraper($req);

// Verificar si ya está corriendo
if ($scraper->isRunning()) {
    echo "El scraping ya está en ejecución\n";
    $info = $scraper->getLockInfo();
    print_r($info);
} else {
    try {
        $resultado = $scraper->run();
        print_r($resultado);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
