<?php
function campanha_normalizar_dominio($dominio) {
    $dominio = strtolower(trim((string)$dominio));
    $dominio = preg_replace('#^https?://#i', '', $dominio);
    $dominio = preg_replace('#^www\.#i', '', $dominio);
    $dominio = preg_replace('#/.*$#', '', $dominio);
    $dominio = preg_replace('/:\d+$/', '', $dominio);
    return trim($dominio);
}
