# Painel de Controle — NEXUS (Monitoramento 360°)

Interface moderna, escura e responsiva para monitoramento centralizado de infraestrutura doméstica e servidores em uma roda interativa 360 graus.

![Painel de Controle](https://raw.githubusercontent.com/igorbmti/Servidor/main/preview.png) *(opcional)*

---

## 🎯 Dispositivos Monitorados

### 1. Linux (NEXUS — Ubuntu Server)
- **Métricas em tempo real:** Uptime contínuo, carga de CPU (`/proc/loadavg`), consumo e percentual de memória RAM (`/proc/meminfo`) e saúde do disco físico `/dev/sda`.
- **Armazenamento e LVM detalhados:**
  - Armazenamento Raiz (`/` — `ubuntu-lv` LVM Ext4).
  - Disco físico `/dev/sda` com capacidade real (`465,8 GB`).
  - Pool LVM livre no Volume Group (`212,7 GB`).
  - Partições físicas Boot (`/boot`) e EFI (`/boot/efi`).
  - Infográfico de alocação segmentado e colorido.

### 2. Windows (Estação de Trabalho Local)
- **Coleta automatizada:** Telemetria enviada via script PowerShell agendado (`coletor_windows.ps1`).
- **Métricas:** Capacidade total, espaço usado, espaço livre e percentual de cada unidade física/lógica.
- **Status de Sincronização:** Indicador de tempo relativo desde o último envio e detecção de conectividade via ICMP ping.

### 3. PlayStation 4 (Modelo Fat Original)
- **Conectividade:** Verificação de latência de rede (Ping), detecção por protocolo de descoberta (DDP UDP 987) e porta TCP 9295.
- **Armazenamento:** Documentação fiel das limitações do sistema original (o PS4 original não expõe telemetria de armazenamento via rede sem modificação de firmware).
- **Controles de Rede:**
  - **Ligar PS4:** Envio de pacotes *Wake-on-LAN* (Magic Packet) e comando nativo *DDP WAKE* (UDP 987).
  - **Colocar em Repouso:** Comando remoto pelo protocolo *Second Screen / Remote Play* autenticado via Python (`ps4_ctl.py`), com suporte a código PIN do console na TV.

---

## 📁 Estrutura de Arquivos

```
├── index.php             # Aplicação principal (HTML5, CSS3, JavaScript e backend PHP)
├── coletor_windows.ps1   # Script PowerShell para coleta periódica de discos no Windows
├── ps4_ctl.py            # Script Python para controle de energia/repouso do PS4 (TCP 9295)
├── api/
│   └── windows.php       # Endpoint REST seguro para recepção da telemetria do Windows
├── .gitignore            # Arquivos ignorados (backups, cache Python, credenciais locais)
└── README.md             # Documentação do projeto
```

---

## 🚀 Como Executar

### No Servidor Linux (Apache / PHP):
1. Clone ou copie os arquivos para a pasta do servidor web:
   ```bash
   sudo mkdir -p /var/www/html/painel
   sudo cp -r . /var/www/html/painel/
   sudo chown -R www-data:www-data /var/www/html/painel
   ```
2. Garanta que o diretório `/var/lib/monitoramento` exista e tenha permissão de escrita pelo Apache:
   ```bash
   sudo mkdir -p /var/lib/monitoramento
   sudo chown -R www-data:www-data /var/lib/monitoramento
   ```

### No Windows (Coletor):
1. Execute o script `coletor_windows.ps1` no PowerShell com privilégios de administrador ou cadastre-o no Agendador de Tarefas do Windows para executar a cada 2 minutos em segundo plano:
   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\caminho\para\coletor_windows.ps1"
   ```

---

## 💡 Recursos da Interface
- **Navegação Circular 360°:** Roda interativa centrada no indicador **Servidor Casa**.
- **Cartões Harmonizados:** Proporções, tipografia e hierarquia visual rigorosamente alinhadas entre todos os dispositivos.
- **Atualização Imediata:** Botão *"Atualizar dados"* para consulta instantânea sob demanda, com feedback em tempo real (*toast*).
- **Responsividade Total:** Adaptado para smartphones, tablets e monitores desktop sem quebras ou rolagem horizontal.
- **Fechamento Automático:** Cartões abertos fecham após 40 segundos de inatividade para preservar a visualização central da roda.
