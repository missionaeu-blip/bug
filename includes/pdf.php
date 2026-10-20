<?php
/**
 * Missiona — Générateur PDF minimal, en PHP pur
 * ------------------------------------------------------------------
 * Aucune bibliothèque, aucun Composer : le fichier PDF est construit
 * octet par octet. Utilise les polices de base (Helvetica) présentes
 * dans tous les lecteurs, en encodage WinAnsi pour les accents français.
 *
 * Unité de travail : le millimètre. Origine en HAUT à gauche, comme on
 * lit une page (le PDF compte depuis le bas, la conversion est interne).
 */

class PdfSimple
{
    private $pages = [];        // flux de contenu de chaque page
    private $buf   = '';        // page en cours
    private $L     = 210.0;     // A4 largeur mm
    private $H     = 297.0;     // A4 hauteur mm
    private $police = 'F1';
    private $taille = 10.0;

    /** Largeurs Helvetica (unités/1000) pour les caractères 32..255. */
    private static $wHelv = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,
        556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,
        350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,
        278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,552,
        400,549,333,333,333,576,537,278,333,333,365,556,834,834,834,611,
        667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,
        722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,
        556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,
        556,556,556,556,556,556,556,549,611,556,556,556,556,500,556,500,
    ];

    public function __construct() { $this->nouvellePage(); }

    public function nouvellePage() {
        if ($this->buf !== '') { $this->pages[] = $this->buf; }
        $this->buf = '';
    }

    /** Convertit une ordonnée « depuis le haut » en coordonnée PDF. */
    private function y($mm) { return ($this->H - $mm) * 2.834645669; }
    private function x($mm) { return $mm * 2.834645669; }

    /**
     * Échappement WinAnsi : les caractères UTF-8 sont convertis en
     * CP1252, puis les caractères réservés du PDF sont protégés.
     */
    private function esc($txt) {
        $s = (string) $txt;
        if (function_exists('iconv')) {
            $c = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
            if ($c !== false) { $s = $c; }
        } elseif (function_exists('mb_convert_encoding')) {
            $s = mb_convert_encoding($s, 'CP1252', 'UTF-8');
        }
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']);
    }

    /** Largeur d'un texte en mm, pour centrer ou aligner à droite. */
    public function largeur($txt, $taille = null, $gras = false) {
        $taille = $taille ?: $this->taille;
        $s = $this->esc($txt);
        $tot = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $o = ord($s[$i]);
            if ($o === 92 && $i + 1 < $n) { continue; } // antislash d'échappement
            $tot += (($o >= 32 && $o <= 255) ? self::$wHelv[$o - 32] : 556);
        }
        // Le gras Helvetica est légèrement plus large : approximation à 3 %.
        $f = $gras ? 1.03 : 1.0;
        return $tot * $taille * $f / 1000 * 0.3527777778;
    }

    public function police($gras = false, $taille = 10) {
        $this->police = $gras ? 'F2' : 'F1';
        $this->taille = $taille;
    }

    /** Écrit du texte. $align : 'g' gauche, 'd' droite, 'c' centré. */
    public function texte($x, $y, $txt, $align = 'g', $gris = null) {
        $gras = ($this->police === 'F2');
        if ($align === 'd') { $x -= $this->largeur($txt, $this->taille, $gras); }
        if ($align === 'c') { $x -= $this->largeur($txt, $this->taille, $gras) / 2; }

        $this->buf .= "BT\n";
        if ($gris !== null) { $this->buf .= sprintf("%.3f %.3f %.3f rg\n", $gris[0], $gris[1], $gris[2]); }
        $this->buf .= '/' . $this->police . ' ' . $this->taille . " Tf\n";
        $this->buf .= sprintf("%.2f %.2f Td\n", $this->x($x), $this->y($y));
        $this->buf .= '(' . $this->esc($txt) . ") Tj\n";
        if ($gris !== null) { $this->buf .= "0 0 0 rg\n"; }
        $this->buf .= "ET\n";
    }

    /** Texte coupé automatiquement sur plusieurs lignes. */
    public function paragraphe($x, $y, $largeur, $txt, $interligne = 4.2) {
        $mots = preg_split('/\s+/', trim((string) $txt));
        $gras = ($this->police === 'F2');
        $ligne = '';
        $n = 0;
        foreach ($mots as $mot) {
            $essai = $ligne === '' ? $mot : $ligne . ' ' . $mot;
            if ($this->largeur($essai, $this->taille, $gras) > $largeur && $ligne !== '') {
                $this->texte($x, $y + $n * $interligne, $ligne);
                $ligne = $mot; $n++;
            } else {
                $ligne = $essai;
            }
        }
        if ($ligne !== '') { $this->texte($x, $y + $n * $interligne, $ligne); $n++; }
        return $n * $interligne;
    }

    public function trait($x1, $y1, $x2, $y2, $ep = 0.2, $gris = 0.8) {
        $this->buf .= sprintf("%.3f G\n%.2f w\n%.2f %.2f m %.2f %.2f l S\n0 G\n",
            $gris, $ep, $this->x($x1), $this->y($y1), $this->x($x2), $this->y($y2));
    }

    /** Rectangle plein. $rvb entre 0 et 1. */
    public function bloc($x, $y, $l, $h, $rvb = [0.95, 0.96, 0.97]) {
        $this->buf .= sprintf("%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re f\n0 0 0 rg\n",
            $rvb[0], $rvb[1], $rvb[2],
            $this->x($x), $this->y($y + $h), $this->x($l), $this->x($h));
    }

    /** Assemble le fichier PDF complet. */
    public function rendu() {
        if ($this->buf !== '') { $this->pages[] = $this->buf; $this->buf = ''; }
        if (!$this->pages) { $this->pages[] = ''; }

        $obj = [];
        $nbP = count($this->pages);

        // 1 = catalogue, 2 = arbre des pages, puis pages et contenus
        $kids = [];
        for ($i = 0; $i < $nbP; $i++) { $kids[] = (3 + $i * 2) . ' 0 R'; }

        $obj[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $obj[2] = "<< /Type /Pages /Count $nbP /Kids [" . implode(' ', $kids) . "] >>";

        $idPolice = 3 + $nbP * 2;
        for ($i = 0; $i < $nbP; $i++) {
            $np = 3 + $i * 2;
            $nc = $np + 1;
            $obj[$np] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] "
                      . "/Resources << /Font << /F1 $idPolice 0 R /F2 " . ($idPolice + 1) . " 0 R >> >> "
                      . "/Contents $nc 0 R >>";
            $flux = $this->pages[$i];
            $obj[$nc] = "<< /Length " . strlen($flux) . " >>\nstream\n" . $flux . "endstream";
        }
        $obj[$idPolice]     = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $obj[$idPolice + 1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        ksort($obj);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $pos = [];
        foreach ($obj as $num => $contenu) {
            $pos[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $contenu . "\nendobj\n";
        }

        $nbObj = max(array_keys($obj)) + 1;
        $debutXref = strlen($pdf);
        $pdf .= "xref\n0 $nbObj\n0000000000 65535 f \n";
        for ($i = 1; $i < $nbObj; $i++) {
            $pdf .= isset($pos[$i])
                ? sprintf("%010d 00000 n \n", $pos[$i])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size $nbObj /Root 1 0 R >>\nstartxref\n$debutXref\n%%EOF\n";
        return $pdf;
    }
}
