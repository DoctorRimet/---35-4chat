/**
 * ForumChat Global Search
 * Глобальный поиск по постам и темам
 */

class ForumSearch {
    constructor(config = {}) {
        // Пути без префикса /ForumChat/ - используем относительные пути
        let searchUrlBase = config.searchUrl;
        
        if (!searchUrlBase) {
            // Используем корневой путь домена
            searchUrlBase = '/api/search.php';
        }
        
        this.searchUrl = searchUrlBase;
        this.minLength = config.minLength || 3;
        this.debounceDelay = config.debounceDelay || 300;
        this.resultsPerPage = config.resultsPerPage || 20;
        
        this.searchInput = document.querySelector(config.inputSelector || '#search-input');
        this.resultsContainer = document.querySelector(config.resultsSelector || '#search-results');
        this.debounceTimer = null;
        this.currentPage = 0;
        this.currentFilters = {};
        
        console.log('ForumSearch initialized');
        console.log('  Search URL:', this.searchUrl);
        console.log('  Input element:', this.searchInput ? '✓' : '✗');
        console.log('  Results container:', this.resultsContainer ? '✓' : '✗');

        if (this.searchInput && this.resultsContainer) {
            this.init();
        }
    }

    init() {
        // Сначала проверим доступность API
        this.checkApiAvailability();
        
        // Слушаем ввод текста
        this.searchInput.addEventListener('input', (e) => this.onInput(e));
        
        // Слушаем клик на кнопку поиска
        const searchBtn = document.querySelector('[data-search-btn]');
        if (searchBtn) {
            searchBtn.addEventListener('click', () => this.search());
        }

        // Enter для поиска
        this.searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.search();
            }
        });
    }

    async checkApiAvailability() {
        try {
            const response = await fetch(this.searchUrl.replace('search.php', 'test.php'));
            if (response.ok) {
                console.log('✓ API доступен');
            } else {
                console.warn('⚠ API вернул статус:', response.status);
            }
        } catch (error) {
            console.error('✗ API недоступен:', error.message);
        }
    }

    onInput(event) {
        const query = event.target.value.trim();

        // Отменяем предыдущий таймер
        clearTimeout(this.debounceTimer);

        // Если запрос меньше минимальной длины
        if (query.length === 0) {
            this.clearResults();
            return;
        }

        if (query.length < this.minLength) {
            this.showMessage(`Введите минимум ${this.minLength} символов`);
            return;
        }

        // Запускаем поиск с задержкой
        this.debounceTimer = setTimeout(() => {
            this.search();
        }, this.debounceDelay);
    }

    async search(page = 0) {
        const query = this.searchInput.value.trim();

        if (query.length < this.minLength) {
            this.showMessage(`Введите минимум ${this.minLength} символов`);
            return;
        }

        try {
            this.showLoading();
            this.currentPage = page;

            const offset = page * this.resultsPerPage;
            let url = `${this.searchUrl}?q=${encodeURIComponent(query)}&limit=${this.resultsPerPage}&offset=${offset}`;
            
            // Добавляем фильтры к URL
            if (this.currentFilters.sort) {
                url += `&sort=${encodeURIComponent(this.currentFilters.sort)}`;
            }
            if (this.currentFilters.author) {
                url += `&author=${encodeURIComponent(this.currentFilters.author)}`;
            }
            if (this.currentFilters.date_from) {
                url += `&date_from=${encodeURIComponent(this.currentFilters.date_from)}`;
            }
            if (this.currentFilters.date_to) {
                url += `&date_to=${encodeURIComponent(this.currentFilters.date_to)}`;
            }
            if (this.currentFilters.min_replies) {
                url += `&min_replies=${encodeURIComponent(this.currentFilters.min_replies)}`;
            }
            
            console.log('[SEARCH] URL запроса:', url);
            
            const response = await fetch(url);

            console.log('[SEARCH] Статус ответа:', response.status);

            if (!response.ok) {
                let errorMsg = 'HTTP ' + response.status;
                try {
                    const error = await response.json();
                    console.error('[SEARCH] Ошибка JSON:', error);
                    errorMsg = error.message || error.error || errorMsg;
                } catch (e) {
                    const text = await response.text();
                    console.error('[SEARCH] Текст ответа:', text);
                    errorMsg += ' - ' + text.substring(0, 100);
                }
                this.showMessage(`❌ Ошибка: ${errorMsg}`);
                return;
            }

            const data = await response.json();
            console.log('[SEARCH] Получены данные:', data);

            if (!data.success) {
                const errorMsg = data.message || data.error || 'Неизвестная ошибка';
                this.showMessage(`❌ ${errorMsg}`);
                return;
            }

            if (data.results.length === 0) {
                this.showMessage('📭 Результатов не найдено');
                return;
            }

            this.displayResults(data);

        } catch (error) {
            console.error('[SEARCH] Исключение:', error);
            this.showMessage('❌ Ошибка при поиске: ' + error.message);
        }
    }

    applyFilters(filters) {
        this.currentFilters = filters;
        this.currentPage = 0;
        this.search();
    }

    displayResults(data) {
        let html = `<div class="search-results-container">`;
        html += `<div class="search-info">
            Найдено <strong>${data.total}</strong> результатов по запросу "<strong>${data.query}</strong>"
        </div>`;

        data.results.forEach(result => {
            const excerpt = this.truncate(result.content_highlighted || result.content, 200);
            
            if (result.type === 'post') {
                html += `
                    <div class="search-result post-result">
                        <div class="result-type-badge">Пост</div>
                        <h3 class="result-title">
                            ${result.title_highlighted || result.title}
                        </h3>
                        <div class="result-topic">
                            <a href="/pages/topic.php?id=${result.topic_id}">
                                Тема: ${result.topic_title}
                            </a>
                        </div>
                        <div class="result-excerpt">${excerpt}</div>
                        <div class="result-meta">
                            <span class="author">👤 ${result.author_name}</span>
                            <span class="date">📅 ${new Date(result.created_at).toLocaleDateString('ru-RU')}</span>
                            <a href="/pages/topic.php?id=${result.topic_id}#post-${result.id}" class="result-link">
                                Читать →
                            </a>
                        </div>
                    </div>
                `;
            } else if (result.type === 'topic') {
                html += `
                    <div class="search-result topic-result">
                        <div class="result-type-badge topic">Тема</div>
                        <h3 class="result-title">
                            <a href="/pages/topic.php?id=${result.id}">
                                ${result.title_highlighted || result.title}
                            </a>
                        </h3>
                        <div class="result-excerpt">${excerpt}</div>
                        <div class="result-meta">
                            <span class="author">👤 ${result.author_name}</span>
                            <span class="date">📅 ${new Date(result.created_at).toLocaleDateString('ru-RU')}</span>
                            <a href="/pages/topic.php?id=${result.id}" class="result-link">
                                Открыть тему →
                            </a>
                        </div>
                    </div>
                `;
            }
        });

        // Пагинация
        if (data.pages > 1) {
            html += `<div class="search-pagination">`;
            
            for (let i = 0; i < data.pages; i++) {
                const activeClass = i === this.currentPage ? 'active' : '';
                html += `<button class="page-btn ${activeClass}" onclick="window.searchInstance.search(${i})">${i + 1}</button>`;
            }
            
            html += `</div>`;
        }

        html += `</div>`;
        this.resultsContainer.innerHTML = html;
    }

    showLoading() {
        this.resultsContainer.innerHTML = `
            <div class="search-loading">
                <div class="spinner"></div>
                <p>Поиск...</p>
            </div>
        `;
    }

    showMessage(message) {
        this.resultsContainer.innerHTML = `
            <div class="search-message">
                <p>${message}</p>
            </div>
        `;
    }

    clearResults() {
        this.resultsContainer.innerHTML = '';
    }

    truncate(text, length = 200) {
        if (!text) return '';
        if (text.length <= length) return text;
        return text.substring(0, length) + '...';
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    window.searchInstance = new ForumSearch();
});
