<?php
/*
 * Classe simples para gerar Payload PIX Estático (QRCPS-MPM)
 */
class PayloadPix {
    private $chave;
    private $beneficiario;
    private $cidade;
    private $txtId;
    private $valor;

    public function setChave($chave) { $this->chave = $chave; return $this; }
    public function setBeneficiario($nome) { $this->beneficiario = substr($nome, 0, 25); return $this; }
    public function setCidade($cidade) { $this->cidade = substr($cidade, 0, 15); return $this; }
    public function setTxid($txid) { $this->txtId = $txid; return $this; }
    public function setValor($valor) { $this->valor = number_format($valor, 2, '.', ''); return $this; }

    private function getValue($id, $value) {
        $size = str_pad(strlen($value), 2, '0', STR_PAD_LEFT);
        return $id . $size . $value;
    }

    public function getPayload() {
        $payload = $this->getValue('00', '01') . // Payload Format Indicator
                   $this->getValue('26', // Merchant Account Information
                       $this->getValue('00', 'br.gov.bcb.pix') . 
                       $this->getValue('01', $this->chave)
                   ) . 
                   $this->getValue('52', '0000') . // Merchant Category Code
                   $this->getValue('53', '986') . // Transaction Currency
                   $this->getValue('54', $this->valor) . // Transaction Amount
                   $this->getValue('58', 'BR') . // Country Code
                   $this->getValue('59', $this->beneficiario) . // Merchant Name
                   $this->getValue('60', $this->cidade) . // Merchant City
                   $this->getValue('62', // Additional Data Field Template
                       $this->getValue('05', $this->txtId ?: '***')
                   ) . 
                   '6304'; // CRC16 ID

        return $payload . $this->getCRC16($payload);
    }

    private function getCRC16($payload) {
        $payload .= "\x00\x00";
        $polynomial = 0x1021;
        $resultado = 0xFFFF;

        if (($length = strlen($payload)) > 0) {
            for ($offset = 0; $offset < $length; $offset++) {
                $resultado ^= (ord($payload[$offset]) << 8);
                for ($bitwise = 0; $bitwise < 8; $bitwise++) {
                    if (($resultado <<= 1) & 0x10000) $resultado ^= $polynomial;
                    $resultado &= 0xFFFF;
                }
            }
        }
        return strtoupper(dechex($resultado));
    }
}
?>