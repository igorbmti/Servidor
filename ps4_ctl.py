#!/usr/bin/env python3
import socket
import struct
import sys
import os
import json
import time
from cryptography.hazmat.primitives.asymmetric import padding
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.serialization import load_pem_public_key
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes

PUBLIC_KEY_PEM = b"""-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxfAO/MDk5ovZpp7xlG9J
JKc4Sg4ztAz+BbOt6Gbhub02tF9bryklpTIyzM0v817pwQ3TCoigpxEcWdTykhDL
cGhAbcp6E7Xh8aHEsqgtQ/c+wY1zIl3fU//uddlB1XuipXthDv6emXsyyU/tJWqc
zy9HCJncLJeYo7MJvf2TE9nnlVm1x4flmD0k1zrvb3MONqoZbKb/TQVuVhBv7SM+
U5PSi3diXIx1Nnj4vQ8clRNUJ5X1tT9XfVmKQS1J513XNZ0uYHYRDzQYujpLWucu
ob7v50wCpUm3iKP1fYCixMP6xFm0jPYz1YQaMV35VkYwc40qgk3av0PDS+1G0dCm
swIDAQAB
-----END PUBLIC KEY-----"""

AUTH_FILE = "/var/www/html/painel/ps4_auth.json"

def wake_discovery(ip='192.168.18.162'):
    try:
        u = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        u.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        u.sendto(b"WAKEUP * HTTP/1.1\nuser-credential:\ndevice-discovery-protocol-version:00020020\n", (ip, 987))
        u.sendto(b"LAUNCH * HTTP/1.1\nuser-credential:\ndevice-discovery-protocol-version:00020020\n", (ip, 987))
        u.close()
    except Exception:
        pass

def send_ps4_standby(ip='192.168.18.162', pin=''):
    pin = str(pin).strip() if pin else ''
    
    # Se nao veio PIN, verifica se ja existe autorizacao salva
    if not pin and os.path.exists(AUTH_FILE):
        try:
            with open(AUTH_FILE, 'r') as f:
                saved = json.load(f)
                pin = saved.get('pin', '')
        except Exception:
            pass

    wake_discovery(ip)
    time.sleep(0.3)

    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.settimeout(5.0)
    try:
        s.connect((ip, 997))
    except Exception as e:
        return {
            'sucesso': False,
            'requer_pin': False,
            'mensagem': f'Não foi possível conectar ao PS4 na porta 997: {str(e)}'
        }

    try:
        # 1. Send ClientHello
        chello = struct.pack("<IIII", 28, 0x6f636370, 0x20000, 0) + (b"\x00" * 12)
        s.sendall(chello)

        # 2. Receive ServerHello
        shello = s.recv(1024)
        if len(shello) < 36:
            return {'sucesso': False, 'requer_pin': False, 'mensagem': 'Resposta de handshake do PS4 inválida.'}
        seed = shello[20:36]

        # 3. RSA Handshake com OAEP SHA-1
        pub_key = load_pem_public_key(PUBLIC_KEY_PEM)
        random_key = b"\x00" * 16
        enc_key = pub_key.encrypt(random_key, padding.OAEP(
            mgf=padding.MGF1(algorithm=hashes.SHA1()),
            algorithm=hashes.SHA1(),
            label=None
        ))
        handshake = struct.pack("<II", 280, 32) + enc_key + seed
        s.sendall(handshake)

        # 4. Cipher AES-128-CBC
        cipher = Cipher(algorithms.AES(random_key), modes.CBC(seed))
        encryptor = cipher.encryptor()
        decryptor = cipher.decryptor()

        def send_enc(data):
            pad_len = 16 - (len(data) % 16)
            if pad_len == 16:
                pad_len = 0
            padded = data + (b"\x00" * pad_len)
            enc = encryptor.update(padded)
            s.sendall(enc)

        def pad(s_val, l):
            b = str(s_val).encode("utf-8")[:l]
            return b + (b"\x00" * (l - len(b)))

        # 5. Send Login Packet
        login = struct.pack("<II", 384, 30)
        login += b"\x00" * 4  # passcode
        login += struct.pack("<I", 513)
        login += pad("", 64)             # accountId
        login += pad("PlayStation", 256) # appLabel
        login += pad("4.4", 16)          # osVersion
        login += pad("PS4 Waker", 16)    # model
        login += pad(pin, 16)            # pinCode

        send_enc(login)

        # 6. Wait for Login response
        try:
            raw = s.recv(1024)
        except socket.timeout:
            raw = None

        if not raw or len(raw) < 12:
            return {
                'sucesso': False,
                'requer_pin': True,
                'mensagem': 'O console recusou a conexão ou o código expirou. Feche e reabra "Adicionar Dispositivo" na TV e digite o código novo.'
            }

        res = decryptor.update(raw)
        res_len, res_type, result_code = struct.unpack("<III", res[:12])

        # result_code:
        # 0 = Login sucesso com conta vinculada
        # 21 = Pareado com sucesso como dispositivo de Segunda Tela
        # 20 = Dispositivo nao registrado (precisa de PIN)
        # 23 = PIN incorreto ou expirado

        if result_code in (0, 21):
            # Salva autorizacao permanentemente para nao pedir PIN novamente
            try:
                with open(AUTH_FILE, "w") as f:
                    json.dump({"autorizado": True, "dispositivo": "PS4 Waker", "pin": pin, "data": time.strftime("%Y-%m-%d %H:%M:%S")}, f)
                os.chmod(AUTH_FILE, 0o666)
            except Exception:
                pass

            # Envia Status ACK (len 12, type 20, status 0)
            status_pkt = struct.pack("<III", 12, 20, 0)
            send_enc(status_pkt)
            time.sleep(0.2)

            # Envia Standby (len 8, type 26)
            standby_pkt = struct.pack("<II", 8, 26)
            send_enc(standby_pkt)

            # Aguarda confirmacao do Standby
            try:
                ans = s.recv(1024)
                if ans:
                    dec_ans = decryptor.update(ans)
                    if len(dec_ans) >= 12:
                        ans_len, ans_type, stat = struct.unpack("<III", dec_ans[:12])
                        if ans_type == 27:
                            if stat == 0:
                                return {
                                    'sucesso': True,
                                    'requer_pin': False,
                                    'mensagem': '✓ Comando aceito! O PS4 está entrando em Modo de Repouso...'
                                }
                            elif stat == 11:
                                return {
                                    'sucesso': False,
                                    'requer_pin': False,
                                    'mensagem': '✓ Pareamento salvo ("PS4 Waker")! Mas o PS4 recusou o repouso agora (Status 11): há um jogo ou menu/diálogo aberto na TV bloqueando a suspensão. Aperte o botão PS ou Círculo no controle para ir à Tela Inicial (ou feche o jogo) e clique novamente em "Colocar em Repouso".'
                                }
                            else:
                                return {
                                    'sucesso': False,
                                    'requer_pin': False,
                                    'mensagem': f'O console respondeu com código de recusa {stat}. Certifique-se de estar na Tela Inicial do PS4.'
                                }
            except Exception:
                pass

            return {
                'sucesso': True,
                'requer_pin': False,
                'mensagem': '✓ Comando de repouso enviado com sucesso ao console!'
            }

        elif result_code == 20:
            return {
                'sucesso': False,
                'requer_pin': True,
                'mensagem': 'O PS4 está aguardando o código de pareamento. Na TV, acesse "Configurações > Configurações de Conexão do Aplicativo Móvel > Adicionar Dispositivo", digite o código de 8 dígitos abaixo e clique em "Conectar".'
            }
        elif result_code == 23:
            return {
                'sucesso': False,
                'requer_pin': True,
                'mensagem': f'O código "{pin}" expirou ou está incorreto. No PS4, aperte Círculo e entre de novo em "Adicionar Dispositivo" para ver o código novo e digite-o abaixo.'
            }
        else:
            return {
                'sucesso': False,
                'requer_pin': False,
                'mensagem': f'O console retornou o código de resposta: {result_code}'
            }
    except Exception as e:
        return {'sucesso': False, 'requer_pin': False, 'mensagem': f'Erro durante a comunicação: {str(e)}'}
    finally:
        s.close()

if __name__ == '__main__':
    pin_arg = sys.argv[1] if len(sys.argv) > 1 else ''
    res = send_ps4_standby('192.168.18.162', pin=pin_arg)
    print(json.dumps(res, ensure_ascii=False))
