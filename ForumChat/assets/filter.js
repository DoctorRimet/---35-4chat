/**
 * Управление фильтрами поиска
 */

class SearchFilter {
    constructor() {
        this.modal = document.getElementById('filterModal');
        this.toggleBtn = document.querySelector('[data-filter-toggle]');
        this.closeBtn = document.querySelector('[data-filter-close]');
        this.applyBtn = document.querySelector('[data-filter-apply]');
        this.resetBtn = document.querySelector('[data-filter-reset]');
        
        // Элементы фильтров
        this.sortSelect = document.getElementById('filterSort');
        this.authorInput = document.getElementById('filterAuthor');
        this.dateFromInput = document.getElementById('filterDateFrom');
        this.dateToInput = document.getElementById('filterDateTo');
        this.minRepliesInput = document.getElementById('filterMinReplies');
        
        this.init();
    }

    init() {
        if (!this.toggleBtn) return;
        
        // События
        this.toggleBtn.addEventListener('click', () => this.openModal());
        if (this.closeBtn) this.closeBtn.addEventListener('click', () => this.closeModal());
        if (this.applyBtn) this.applyBtn.addEventListener('click', () => this.applyFilters());
        if (this.resetBtn) this.resetBtn.addEventListener('click', () => this.resetFilters());
        
        // Закрытие по клику вне модального окна
        window.addEventListener('click', (e) => {
            if (e.target === this.modal) {
                this.closeModal();
            }
        });
        
        // Загружаем сохраненные фильтры
        this.loadFilters();
    }

    openModal() {
        if (this.modal) {
            this.modal.classList.add('active');
        }
    }

    closeModal() {
        if (this.modal) {
            this.modal.classList.remove('active');
        }
    }

    resetFilters() {
        if (this.sortSelect) this.sortSelect.value = 'date_desc';
        if (this.authorInput) this.authorInput.value = '';
        if (this.dateFromInput) this.dateFromInput.value = '';
        if (this.dateToInput) this.dateToInput.value = '';
        if (this.minRepliesInput) this.minRepliesInput.value = '0';
        
        // Очищаем localStorage
        localStorage.removeItem('searchFilters');
        
        // Применяем фильтры
        this.applyFilters();
    }

    applyFilters() {
        // Собираем фильтры
        const filters = {
            sort: this.sortSelect ? this.sortSelect.value : 'date_desc',
            author: this.authorInput ? this.authorInput.value : '',
            date_from: this.dateFromInput ? this.dateFromInput.value : '',
            date_to: this.dateToInput ? this.dateToInput.value : '',
            min_replies: this.minRepliesInput ? this.minRepliesInput.value : '0'
        };
        
        // Сохраняем в localStorage
        localStorage.setItem('searchFilters', JSON.stringify(filters));
        
        // Закрываем модальное окно
        this.closeModal();
        
        // Выполняем поиск с фильтрами
        if (window.searchInstance) {
            window.searchInstance.applyFilters(filters);
        }
    }

    loadFilters() {
        const saved = localStorage.getItem('searchFilters');
        if (saved) {
            const filters = JSON.parse(saved);
            
            if (this.sortSelect && filters.sort) this.sortSelect.value = filters.sort;
            if (this.authorInput && filters.author) this.authorInput.value = filters.author;
            if (this.dateFromInput && filters.date_from) this.dateFromInput.value = filters.date_from;
            if (this.dateToInput && filters.date_to) this.dateToInput.value = filters.date_to;
            if (this.minRepliesInput && filters.min_replies) this.minRepliesInput.value = filters.min_replies;
        }
    }

    getFilters() {
        return {
            sort: this.sortSelect ? this.sortSelect.value : 'date_desc',
            author: this.authorInput ? this.authorInput.value : '',
            date_from: this.dateFromInput ? this.dateFromInput.value : '',
            date_to: this.dateToInput ? this.dateToInput.value : '',
            min_replies: this.minRepliesInput ? this.minRepliesInput.value : '0'
        };
    }
}

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', () => {
    window.searchFilter = new SearchFilter();
});
