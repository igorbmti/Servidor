(function(){
  const filterDate = document.getElementById('filterDate');
  const filterText = document.getElementById('filterText');
  const clearFilters = document.getElementById('clearFilters');
  const closeAgenda = document.getElementById('closeAgenda');
  const showRevenue = document.getElementById('showRevenue');
  const revenueValue = document.getElementById('revenueValue');
  const totalDayCount = document.getElementById('totalDayCount');
  const nextAppointmentTime = document.getElementById('nextAppointmentTime');
  const adminBookings = document.getElementById('adminBookings');
  const emptyAdmin = document.getElementById('emptyAdmin');

  // Elementos Mobile (Drawer / Sidebar)
  const sidebar = document.getElementById('adminSidebar');
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebarClose = document.getElementById('sidebarClose');
  const sidebarBackdrop = document.getElementById('sidebarBackdrop');

  // Elementos do Modal de Bloqueio de Agenda
  const closeAgendaModal = document.getElementById('closeAgendaModal');
  const closeModalDate = document.getElementById('closeModalDate');
  const closeReason = document.getElementById('closeReason');
  const cancelCloseBtn = document.getElementById('cancelCloseBtn');
  const confirmCloseBtn = document.getElementById('confirmCloseBtn');

  // Elementos do Modal de Confirmação de Exclusão
  const confirmDeleteModal = document.getElementById('confirmDeleteModal');
  const deleteModalText = document.getElementById('deleteModalText');
  const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
  const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

  let pendingDeletePhone = null;

  // Sistema de Toasts Modernos
  function showToast(message, type = 'info', title = '') {
    let container = document.getElementById('toastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toastContainer';
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    const icons = {
      success: '✅',
      error: '❌',
      warning: '⚠️',
      info: '📊'
    };
    const defaultTitles = {
      success: 'Sucesso',
      error: 'Erro',
      warning: 'Aviso',
      info: 'Informação'
    };
    toast.innerHTML = `
      <div class="toast-icon">${icons[type] || '📊'}</div>
      <div class="toast-content">
        <div class="toast-title">${title || defaultTitles[type] || 'Aviso'}</div>
        <div>${String(message).replace(/\n/g, '<br>')}</div>
      </div>
      <button type="button" class="toast-close" aria-label="Fechar">&times;</button>
    `;
    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));

    const removeToast = () => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 250);
    };

    toast.querySelector('.toast-close').addEventListener('click', removeToast);
    setTimeout(removeToast, 4500);
  }

  // Controle de Navegação Mobile (Drawer)
  function openSidebar() {
    sidebar.classList.add('open');
    sidebarBackdrop.classList.add('active');
  }

  function closeSidebar() {
    sidebar.classList.remove('open');
    sidebarBackdrop.classList.remove('active');
  }

  sidebarToggle?.addEventListener('click', openSidebar);
  sidebarClose?.addEventListener('click', closeSidebar);
  sidebarBackdrop?.addEventListener('click', closeSidebar);

  const prices = {
    'Aparar a barba': 15,
    'Barba com navalha': 20,
    'Corte com navalha': 35,
    'Corte com tesoura': 30,
    'Corte de cabelo': 25,
    'Corte degradê': 30,
    'Corte personalizado': 40,
    'Cortes infantis': 20,
    'Crianças': 20
  };

  function loadBookings(){
    return JSON.parse(localStorage.getItem('barbeariaBookings') || '[]');
  }

  function saveBookings(bookings){
    localStorage.setItem('barbeariaBookings', JSON.stringify(bookings));
  }

  function loadClosedDates(){
    return JSON.parse(localStorage.getItem('barbeariaClosedDates') || '{}');
  }

  function saveClosedDates(closedDates){
    localStorage.setItem('barbeariaClosedDates', JSON.stringify(closedDates));
  }

  function normalizePhone(phone){
    return String(phone || '').replace(/\D/g, '');
  }

  function escapeHTML(value){
    return String(value || '').replace(/[&<>"']/g, function(char){
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[char];
    });
  }

  function sortBookings(bookings){
    return bookings.slice().sort(function(a, b){
      return `${a.data || ''} ${a.horario || ''}`.localeCompare(`${b.data || ''} ${b.horario || ''}`);
    });
  }

  function getDateBookings(date){
    if(!date) return [];
    return sortBookings(loadBookings()).filter(function(booking){
      return booking.data === date;
    });
  }

  function getSelectedDateBookings(){
    const date = filterDate.value;
    if(!date) return [];

    const text = filterText.value.trim().toLowerCase();
    const phone = normalizePhone(text);

    return getDateBookings(date).filter(function(booking){
      const fullName = `${booking.nome || ''} ${booking.sobrenome || ''}`.toLowerCase();
      const matchesText = !text ||
        fullName.includes(text) ||
        normalizePhone(booking.telefone).includes(phone);

      return matchesText;
    });
  }

  function getRevenue(bookings){
    return bookings.reduce(function(total, booking){
      return total + (prices[booking.corte] || 0);
    }, 0);
  }

  function formatCurrency(value){
    return `R$ ${value.toFixed(2).replace('.', ',')}`;
  }

  function getRevenueStorageKey(date){
    return `barbeariaRevenueOpen_${date}`;
  }

  function getEndOfDay(date){
    return new Date(`${date}T19:00:00`);
  }

  function shouldShowRevenue(){
    const date = filterDate.value;
    if(!date) return false;
    const opened = localStorage.getItem(getRevenueStorageKey(date)) === 'true';
    const beforeEnd = new Date() < getEndOfDay(date);
    if(opened && !beforeEnd){
      localStorage.removeItem(getRevenueStorageKey(date));
      return false;
    }
    return opened && beforeEnd;
  }

  function updateClosedState(){
    const date = filterDate.value;
    const closed = date && loadClosedDates()[date];
    closeAgenda.innerHTML = closed ? '<span>🔒</span> Agenda Fechada' : '<span>🔒</span> Bloquear Agenda';
    closeAgenda.disabled = Boolean(closed);
  }

  function renderStats(bookings, date){
    totalDayCount.textContent = bookings.length;

    const now = new Date();
    const todayStr = now.toLocaleDateString('en-CA'); // yyyy-mm-dd
    const isToday = date === todayStr;
    const currentHM = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
    
    const next = bookings.find(b => b.horario > currentHM);
    nextAppointmentTime.textContent = (isToday && next) ? next.horario : (bookings[0]?.horario || '--:--');

    // Atualiza Faturamento (se habilitado)
    if(shouldShowRevenue()){
      revenueValue.textContent = formatCurrency(getRevenue(bookings));
      showRevenue.textContent = 'Ocultar saldo';
    } else {
      revenueValue.textContent = 'R$ ••••';
      showRevenue.textContent = 'Mostrar saldo';
    }
  }

  function renderBookings(){
    const date = filterDate.value;
    
    if(!date){
      totalDayCount.textContent = '0';
      nextAppointmentTime.textContent = '--:--';
      revenueValue.textContent = 'R$ 0,00';
      adminBookings.innerHTML = '';
      emptyAdmin.textContent = 'Selecione uma data para visualizar os agendamentos.';
      emptyAdmin.classList.remove('hidden');
      return;
    }

    const filtered = getSelectedDateBookings();
    const dayBookings = getDateBookings(date);
    adminBookings.innerHTML = '';
    updateClosedState();
    renderStats(dayBookings, date);

    if(filtered.length === 0){
      emptyAdmin.textContent = 'Nenhum agendamento encontrado para esta data ou filtro.';
      emptyAdmin.classList.remove('hidden');
      return;
    }

    emptyAdmin.classList.add('hidden');
    filtered.forEach(function(booking){
      const card = document.createElement('article');
      card.className = 'admin-card';
      card.innerHTML = `
        <div class="col-name">
          <strong style="color: #fff; font-size: 0.95rem;">${escapeHTML(booking.nome)} ${escapeHTML(booking.sobrenome)}</strong>
        </div>
        <div class="col-phone" style="color: rgba(255,255,255,0.7); font-size: 0.9rem;">
          <a href="https://wa.me/55${escapeHTML(normalizePhone(booking.telefone))}" target="_blank" style="color: inherit; text-decoration: none;" title="Abrir conversa no WhatsApp">
            💬 ${escapeHTML(booking.telefone)}
          </a>
        </div>
        <div class="col-service">
          <span class="badge-service">${escapeHTML(booking.corte)}</span>
        </div>
        <div class="col-time" style="font-weight: 700; color: #D4AF37; font-size: 1rem;">
          ${escapeHTML(booking.horario || '—')}
        </div>
        <div class="col-actions">
          <button type="button" class="admin-delete" data-phone="${escapeHTML(normalizePhone(booking.telefone))}" data-name="${escapeHTML(booking.nome)} ${escapeHTML(booking.sobrenome)}">
            Remover
          </button>
        </div>
      `;
      adminBookings.appendChild(card);
    });
  }

  // Exclusão de Agendamento com Modal Moderno
  adminBookings.addEventListener('click', function(event){
    const button = event.target.closest('.admin-delete');
    if(!button) return;

    pendingDeletePhone = button.dataset.phone;
    const clientName = button.dataset.name || 'este cliente';
    deleteModalText.textContent = `Deseja realmente cancelar o agendamento de ${clientName}? O horário será liberado imediatamente.`;
    confirmDeleteModal.classList.add('active');
    confirmDeleteModal.setAttribute('aria-hidden', 'false');
  });

  function closeDeleteModal() {
    confirmDeleteModal.classList.remove('active');
    confirmDeleteModal.setAttribute('aria-hidden', 'true');
    pendingDeletePhone = null;
  }

  cancelDeleteBtn.addEventListener('click', closeDeleteModal);

  confirmDeleteBtn.addEventListener('click', function(){
    if(!pendingDeletePhone) return;
    const bookings = loadBookings().filter(function(booking){
      return normalizePhone(booking.telefone) !== pendingDeletePhone;
    });
    saveBookings(bookings);
    closeDeleteModal();
    showToast('Agendamento cancelado com sucesso.', 'success');
    renderBookings();
  });

  // Fechamento de Agenda com Modal Moderno
  closeAgenda.addEventListener('click', function(){
    const date = filterDate.value;
    if(!date){
      showToast('Selecione uma data no filtro antes de fechar a agenda.', 'warning');
      return;
    }

    const formatted = date.split('-').reverse().join('/');
    closeModalDate.textContent = formatted;
    closeReason.value = '';
    closeAgendaModal.classList.add('active');
    closeAgendaModal.setAttribute('aria-hidden', 'false');
  });

  function closeAgendaModalDismiss() {
    closeAgendaModal.classList.remove('active');
    closeAgendaModal.setAttribute('aria-hidden', 'true');
  }

  cancelCloseBtn.addEventListener('click', closeAgendaModalDismiss);

  confirmCloseBtn.addEventListener('click', function(){
    const date = filterDate.value;
    const reasonText = closeReason.value.trim();

    if(!reasonText){
      showToast('Por favor, informe a justificativa do fechamento.', 'warning');
      closeReason.focus();
      return;
    }

    const closedDates = loadClosedDates();
    closedDates[date] = {
      reason: reasonText,
      closedAt: new Date().toISOString()
    };
    saveClosedDates(closedDates);

    closeAgendaModalDismiss();
    showToast(`Agenda de ${date.split('-').reverse().join('/')} bloqueada com sucesso.`, 'success');
    renderBookings();
  });

  // Fechar modais ao teclar ESC ou clicar fora
  window.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
      closeDeleteModal();
      closeAgendaModalDismiss();
      closeSidebar();
    }
  });

  confirmDeleteModal.addEventListener('click', function(e){
    if(e.target === confirmDeleteModal) closeDeleteModal();
  });

  closeAgendaModal.addEventListener('click', function(e){
    if(e.target === closeAgendaModal) closeAgendaModalDismiss();
  });

  // Faturamento Toggle
  showRevenue.addEventListener('click', function(){
    const date = filterDate.value;
    if(!date){
      showToast('Selecione uma data para verificar o faturamento.', 'warning');
      return;
    }
    const currentStatus = shouldShowRevenue();
    if(currentStatus){
      localStorage.removeItem(getRevenueStorageKey(date));
    } else {
      localStorage.setItem(getRevenueStorageKey(date), 'true');
    }
    renderBookings();
  });

  filterDate.addEventListener('input', renderBookings);
  filterText.addEventListener('input', renderBookings);
  clearFilters.addEventListener('click', function(){
    const today = new Date().toLocaleDateString('en-CA');
    filterDate.value = today;
    filterText.value = '';
    renderBookings();
    showToast('Filtros restaurados para o dia de hoje.', 'info');
  });

  // Inicialização
  function init(){
    const today = new Date().toLocaleDateString('en-CA');
    filterDate.value = today;
    renderBookings();
  }

  init();
})();
