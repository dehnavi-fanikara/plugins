/**
 * Hyper Search - AJAX Search Engine v4.0.4
 *
 * CHANGELOG v4.0.4:
 * - FIX: Dropdown container with absolute positioning for header
 * - ADD: Support for fnk_content_sdfsr meta for search excerpts
 * - FIX: Code cleanup and optimization
 *
 * @package Fanikara
 * @version 4.0.4
 */

(function ($) {
    'use strict';

    var VERSION = '4.0.4';
    var SEARCH_HISTORY_KEY = 'fnk_hyper_search_history';
    var MAX_HISTORY_ITEMS = 10;

    // ============================================================
    // ICONS (SVG)
    // ============================================================
    var ICONS = {
        search: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 17C13.4183 17 17 13.4183 17 9C17 4.58172 13.4183 1 9 1C4.58172 1 1 4.58172 1 9C1 13.4183 4.58172 17 9 17Z" stroke="#6B7A8F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 19L14.65 14.65" stroke="#6B7A8F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        reset: '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13.5 4.5L4.5 13.5" stroke="#9AA4B2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.5 4.5L13.5 13.5" stroke="#9AA4B2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        history: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 1V8L11 11" stroke="#9AA4B2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8" cy="8" r="7" stroke="#9AA4B2" stroke-width="1.5"/></svg>',
        dropdown: '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 4L6 8L10 4" stroke="#6B7A8F" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    };

    // ============================================================
    // SEARCHABLE SELECT
    // ============================================================
    var SearchableSelect = function (selectElement, options) {
        this.$select = $(selectElement);
        this.options = options || {};
        this.placeholder = this.options.placeholder || 'جستجو...';
        this.$wrapper = null;
        this.$searchInput = null;
        this.$optionsList = null;
        this.isOpen = false;
        this.onChangeCallback = null;
        this.init();
    };

    SearchableSelect.prototype.init = function () {
        var self = this;
        var $original = this.$select;
        var id = 'ss_' + Math.random().toString(36).substr(2, 9);

        $original.css('display', 'none');

        this.$wrapper = $('<div class="fnk_hyper_search__ss-wrapper" data-id="' + id + '"></div>');
        this.$wrapper.insertAfter($original);

        this.$display = $('<div class="fnk_hyper_search__ss-display">' +
            '<span class="fnk_hyper_search__ss-display-text">' + this.getSelectedText() + '</span>' +
            '<span class="fnk_hyper_search__ss-display-arrow">' + ICONS.dropdown + '</span>' +
            '</div>');
        this.$wrapper.append(this.$display);

        this.$dropdown = $('<div class="fnk_hyper_search__ss-dropdown"></div>');
        this.$wrapper.append(this.$dropdown);

        this.$searchInput = $('<input type="text" class="fnk_hyper_search__ss-search" placeholder="' + this.placeholder + '">');
        this.$dropdown.append(this.$searchInput);

        this.$optionsList = $('<ul class="fnk_hyper_search__ss-options"></ul>');
        this.$dropdown.append(this.$optionsList);

        this.populateOptions();

        this.$display.on('click', function (e) {
            e.stopPropagation();
            self.toggle();
        });

        this.$searchInput.on('input', function () {
            self.filterOptions($(this).val());
        });

        this.$searchInput.on('keydown', function (e) {
            if (e.key === 'Escape') {
                self.close();
            } else if (e.key === 'Enter') {
                var $first = self.$optionsList.find('.fnk_hyper_search__ss-option:visible:first');
                if ($first.length) {
                    $first.trigger('click');
                }
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                var $visible = self.$optionsList.find('.fnk_hyper_search__ss-option:visible');
                var $active = $visible.filter('.fnk_hyper_search__ss-option--active');

                if ($active.length) {
                    $active.removeClass('fnk_hyper_search__ss-option--active');
                    var $next = (e.key === 'ArrowDown') ? $active.nextAll(':visible:first') : $active.prevAll(':visible:first');
                    if ($next.length) {
                        $next.addClass('fnk_hyper_search__ss-option--active');
                    } else {
                        $visible.first().addClass('fnk_hyper_search__ss-option--active');
                    }
                } else {
                    $visible.first().addClass('fnk_hyper_search__ss-option--active');
                }
            }
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest(self.$wrapper).length) {
                self.close();
            }
        });

        $original.on('change', function () {
            self.updateDisplay();
            if (typeof self.onChangeCallback === 'function') {
                self.onChangeCallback($(this).val());
            }
        });
    };

    SearchableSelect.prototype.onChange = function (callback) {
        this.onChangeCallback = callback;
    };

    SearchableSelect.prototype.populateOptions = function () {
        var self = this;
        this.$optionsList.empty();

        this.$select.find('option').each(function () {
            var $option = $(this);
            var value = $option.val();
            var label = $option.text();

            if (value === '') {
                var $li = $('<li class="fnk_hyper_search__ss-option fnk_hyper_search__ss-option--placeholder" data-value="' + value + '">' + label + '</li>');
                self.$optionsList.append($li);
            } else {
                var $li = $('<li class="fnk_hyper_search__ss-option" data-value="' + value + '">' + label + '</li>');
                self.$optionsList.append($li);

                $li.on('click', function () {
                    var val = $(this).data('value');
                    self.selectValue(val);
                });

                $li.on('mouseenter', function () {
                    self.$optionsList.find('.fnk_hyper_search__ss-option--active').removeClass('fnk_hyper_search__ss-option--active');
                    $(this).addClass('fnk_hyper_search__ss-option--active');
                });
            }
        });

        this.updateDisplay();
    };

    SearchableSelect.prototype.filterOptions = function (term) {
        term = term.toLowerCase().trim();

        this.$optionsList.find('.fnk_hyper_search__ss-option').each(function () {
            var $option = $(this);
            var text = $option.text().toLowerCase();

            if (term === '' || text.indexOf(term) !== -1) {
                $option.show();
            } else {
                $option.hide();
            }
        });

        var $visible = this.$optionsList.find('.fnk_hyper_search__ss-option:visible');
        this.$optionsList.find('.fnk_hyper_search__ss-no-results').remove();

        if ($visible.length === 0) {
            this.$optionsList.append('<li class="fnk_hyper_search__ss-no-results">نتیجه‌ای یافت نشد</li>');
        }
    };

    SearchableSelect.prototype.selectValue = function (value) {
        this.$select.val(value).trigger('change');
        this.close();
        this.updateDisplay();
    };

    SearchableSelect.prototype.getSelectedText = function () {
        var $selected = this.$select.find('option:selected');
        return $selected.text() || this.$select.find('option:first').text();
    };

    SearchableSelect.prototype.updateDisplay = function () {
        var text = this.getSelectedText();
        this.$display.find('.fnk_hyper_search__ss-display-text').text(text);

        var selectedVal = this.$select.val();
        this.$optionsList.find('.fnk_hyper_search__ss-option').removeClass('fnk_hyper_search__ss-option--selected');
        this.$optionsList.find('.fnk_hyper_search__ss-option[data-value="' + selectedVal + '"]').addClass('fnk_hyper_search__ss-option--selected');
    };

    SearchableSelect.prototype.toggle = function () {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    };

    SearchableSelect.prototype.open = function () {
        this.isOpen = true;
        this.$dropdown.show();
        this.$display.addClass('fnk_hyper_search__ss-display--open');
        setTimeout(function () {
            this.$searchInput.focus();
        }.bind(this), 50);
        this.filterOptions('');
    };

    SearchableSelect.prototype.close = function () {
        this.isOpen = false;
        this.$dropdown.hide();
        this.$display.removeClass('fnk_hyper_search__ss-display--open');
    };

    // ============================================================
    // SEARCH HISTORY MANAGER
    // ============================================================
    var SearchHistory = {
        get: function () {
            try {
                var data = localStorage.getItem(SEARCH_HISTORY_KEY);
                return data ? JSON.parse(data) : [];
            } catch (e) {
                return [];
            }
        },
        add: function (term, cityId, cityName) {
            if (!term || term.length < 2) return;

            var history = this.get();
            var entry = {
                term: term,
                city_id: cityId,
                city_name: cityName,
                timestamp: Date.now()
            };

            history = history.filter(function(item) {
                return !(item.term === entry.term && item.city_id === entry.city_id);
            });

            history.unshift(entry);

            if (history.length > MAX_HISTORY_ITEMS) {
                history = history.slice(0, MAX_HISTORY_ITEMS);
            }

            try {
                localStorage.setItem(SEARCH_HISTORY_KEY, JSON.stringify(history));
            } catch (e) {}
        },
        clear: function () {
            try {
                localStorage.removeItem(SEARCH_HISTORY_KEY);
            } catch (e) {}
        }
    };

    // ============================================================
    // HYPER SEARCH MAIN CLASS
    // ============================================================
    var HyperSearch = function (container) {
        this.container = container;
        this.$container = $(container);

        var rawAjaxValue = this.$container.data('ajax');
        this.ajax = String(rawAjaxValue) === 'true';

        this.itemsPerPage = parseInt(this.$container.data('items-per-page')) || 10;
        this.mode = this.$container.data('mode') || 'text';
        this.debug = fanikarHyperSearch.debug || false;

        this.$form = this.$container.find('.fnk_hyper_search__form');
        this.$citySelect = this.$container.find('.fnk_hyper_search__select--city');
        this.$serviceSelect = this.$container.find('.fnk_hyper_search__select--service');
        this.$searchInput = this.$container.find('.fnk_hyper_search__input');
        this.$dropdownWrapper = this.$container.find('.fnk_hyper_search__dropdown-wrapper');
        this.$suggestions = this.$container.find('.fnk_hyper_search__suggestions');
        this.$results = this.$container.find('.fnk_hyper_search__results');
        this.$resultsInner = this.$container.find('.fnk_hyper_search__results-inner');
        this.$loader = this.$container.find('.fnk_hyper_search__loader');
        this.$submitBtn = this.$container.find('.fnk_hyper_search__submit-btn');
        this.$searchIcon = this.$container.find('.fnk_hyper_search__submit');
        this.$resetBtn = null;

        this.currentPage = 1;
        this.hasMore = false;
        this.isLoading = false;
        this.suggestTimeout = null;
        this.searchableSelects = [];
        this.currentCityId = null;
        this.currentCityName = '';
        this.searchHistory = SearchHistory;
        this.dropdownVisible = false;

        if (this.debug) {
            console.log('════════════════════════════════════════');
            console.log('🔍 HyperSearch v' + VERSION + ' initialized');
            console.log('════════════════════════════════════════');
            console.log('  Mode:', this.mode);
            console.log('  Items per page:', this.itemsPerPage);
            console.log('════════════════════════════════════════');
        }

        this.init();
    };

    HyperSearch.prototype.init = function () {
        var self = this;

        this.$searchIcon.html(ICONS.search);
        this.addResetButton();

        if (this.$citySelect.length) {
            var citySelect = new SearchableSelect(this.$citySelect, { placeholder: 'نام شهر...' });
            citySelect.onChange(function (value) {
                self.clearValidationErrors();
                var cityText = self.$citySelect.find('option:selected').text();
                if (value && cityText && cityText !== 'انتخاب شهر...') {
                    self.currentCityId = value;
                    self.currentCityName = cityText;
                }

                if (self.mode === 'text' && value) {
                    var searchTerm = self.$searchInput.val().trim();
                    if (searchTerm.length === 0) {
                        self.$resultsInner.empty();
                        self.$results.removeClass('fnk_hyper_search__results--visible');
                        self.showRecentSearches();
                        return;
                    }
                    if (searchTerm.length >= fanikarHyperSearch.min_chars) {
                        if (self.debug) console.log('🔄 Auto-search triggered by city change');
                        setTimeout(function () { self.performSearch(1); }, 300);
                    }
                }
            });
            this.searchableSelects.push(citySelect);
        }

        if (this.$serviceSelect.length) {
            var serviceSelect = new SearchableSelect(this.$serviceSelect, { placeholder: 'جستجوی خدمات...' });
            serviceSelect.onChange(function () { self.clearValidationErrors(); });
            this.searchableSelects.push(serviceSelect);
        }

        this.$form.on('submit', function (e) {
            e.preventDefault();
            self.handleSearch();
        });

        if (this.mode === 'text') {
            this.$searchInput.on('input', function () {
                var term = $(this).val();
                self.handleInput(term);
            });

            this.$searchInput.on('focus', function () {
                var term = $(this).val().trim();
                if (term.length === 0) {
                    self.showRecentSearches();
                }
            });

            this.$searchInput.on('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.handleSearch();
                } else {
                    self.handleKeydown(e);
                }
            });

            this.$resetBtn.on('click', function (e) {
                e.preventDefault();
                self.resetSearch();
            });

            if (this.$searchIcon.length) {
                this.$searchIcon.on('click', function (e) {
                    e.preventDefault();
                    self.handleSearch();
                });
            }
        }

        if (this.mode === 'select') {
            this.$citySelect.on('change', function () { self.checkAndSearch(); });
            this.$serviceSelect.on('change', function () { self.checkAndSearch(); });
            if (this.$submitBtn.length) {
                this.$submitBtn.on('click', function (e) {
                    e.preventDefault();
                    self.handleSearch();
                });
            }
            if (this.ajax && this.$citySelect.val() && this.$serviceSelect.val()) {
                setTimeout(function () { self.performSearch(1); }, 300);
            }
        }

        $(document).on('click', function (e) {
            var $target = $(e.target);
            if (!$target.closest(self.$form).length) {
                self.hideDropdown();
                self.searchableSelects.forEach(function(ss) {
                    if (ss.isOpen) ss.close();
                });
            }
        });

        this.$container.on('click', '.fnk_hyper_search__load-more', function () {
            self.performSearch(self.currentPage + 1);
        });

        var initialCity = this.$citySelect.val();
        if (initialCity) {
            var searchTerm = this.$searchInput.val().trim();
            if (searchTerm.length === 0) {
                setTimeout(function () { self.showRecentSearches(); }, 500);
            }
        }
    };

    HyperSearch.prototype.addResetButton = function () {
        var self = this;
        this.$resetBtn = $('<button type="button" class="fnk_hyper_search__reset" title="پاک کردن جستجو" style="display:none;">' + ICONS.reset + '</button>');
        this.$searchInput.after(this.$resetBtn);
        this.$searchInput.on('input', function () {
            var hasValue = $(this).val().trim().length > 0;
            self.$resetBtn.toggle(hasValue);
        });
    };

    HyperSearch.prototype.resetSearch = function () {
        this.$searchInput.val('');
        this.$resetBtn.hide();

        this.$resultsInner.empty();
        this.$results.removeClass('fnk_hyper_search__results--visible');
        this.hasMore = false;
        this.currentPage = 1;

        this.$suggestions.empty();
        this.$suggestions.removeClass('fnk_hyper_search__suggestions--visible');
        this.hideDropdown();
        this.clearValidationErrors();

        if (this.$citySelect.val()) {
            this.showRecentSearches();
        }

        this.$searchInput.focus();

        if (this.debug) {
            console.log('🔄 Search reset');
        }
    };

    HyperSearch.prototype.showDropdown = function () {
        if (!this.dropdownVisible) {
            this.$dropdownWrapper.css('display', 'block');
            this.$dropdownWrapper.addClass('fnk_hyper_search__dropdown-wrapper--visible');
            this.dropdownVisible = true;
        }
    };

    HyperSearch.prototype.hideDropdown = function () {
        if (this.dropdownVisible) {
            this.$dropdownWrapper.css('display', 'none');
            this.$dropdownWrapper.removeClass('fnk_hyper_search__dropdown-wrapper--visible');
            this.dropdownVisible = false;
        }
    };

    HyperSearch.prototype.clearValidationErrors = function () {
        this.$resultsInner.empty();
        this.$results.removeClass('fnk_hyper_search__results--visible');
        this.hasMore = false;
        this.$container.find('.fnk_hyper_search__ss-wrapper').css('border-color', '');
    };

    HyperSearch.prototype.checkAndSearch = function () {
        var cityVal = this.$citySelect.val();
        var serviceVal = this.$serviceSelect.val();
        this.clearValidationErrors();
        if (cityVal && serviceVal) {
            this.performSearch(1);
        }
    };

    HyperSearch.prototype.handleSearch = function () {
        if (this.debug) console.log('🔍 handleSearch called');
        if (!this.$citySelect.val()) {
            this.showValidationError(fanikarHyperSearch.strings.select_city_required);
            return;
        }
        if (this.mode === 'select' && !this.$serviceSelect.val()) {
            this.showValidationError('لطفاً یک خدمت انتخاب کنید');
            return;
        }
        if (this.mode === 'text' && !this.$searchInput.val().trim()) {
            this.showValidationError('لطفاً یک عبارت جستجو وارد کنید');
            return;
        }
        this.performSearch(1);
    };

    HyperSearch.prototype.showValidationError = function (message) {
        this.$resultsInner.html('<div class="fnk_hyper_search__no-results">' +
            '<span class="fnk_hyper_search__no-results-icon">⚠️</span>' +
            '<h4 class="fnk_hyper_search__no-results-title">توجه</h4>' +
            '<p class="fnk_hyper_search__no-results-text">' + message + '</p>' +
            '</div>');
        this.$results.addClass('fnk_hyper_search__results--visible');
        this.showDropdown();
        if (!this.$citySelect.val()) {
            this.$citySelect.closest('.fnk_hyper_search__ss-wrapper').css('border-color', '#ff6b6b');
        }
    };

    HyperSearch.prototype.showRecentSearches = function () {
        var self = this;
        var history = this.searchHistory.get();

        if (history.length === 0) {
            this.$suggestions.empty();
            this.$suggestions.removeClass('fnk_hyper_search__suggestions--visible');
            this.hideDropdown();
            return;
        }

        var cityId = this.$citySelect.val();
        var filteredHistory = history;
        if (cityId) {
            filteredHistory = history.filter(function(item) {
                return String(item.city_id) === String(cityId);
            });
        }

        if (filteredHistory.length === 0) {
            this.$suggestions.empty();
            this.$suggestions.removeClass('fnk_hyper_search__suggestions--visible');
            this.hideDropdown();
            return;
        }

        var html = '<div class="fnk_hyper_search__suggestions-header">' +
            '<span class="fnk_hyper_search__suggestions-title">جستجوهای اخیر شما</span>' +
            '<button class="fnk_hyper_search__suggestions-clear" type="button">حذف همه</button>' +
            '</div>';

        filteredHistory.forEach(function(item) {
            var displayText = item.term;
            if (item.city_name) {
                displayText = item.term + ' ( ' + item.city_name + ' )';
            }
            html += '<div class="fnk_hyper_search__suggestion fnk_hyper_search__suggestion--history" data-term="' + item.term + '" data-city-id="' + item.city_id + '">' +
                '<span class="fnk_hyper_search__suggestion-icon">' + ICONS.history + '</span>' +
                '<span class="fnk_hyper_search__suggestion-title">' + displayText + '</span>' +
                '</div>';
        });

        this.$suggestions.html(html);
        this.$suggestions.addClass('fnk_hyper_search__suggestions--visible');
        this.$results.removeClass('fnk_hyper_search__results--visible');
        this.showDropdown();

        this.$suggestions.find('.fnk_hyper_search__suggestion--history').on('click', function () {
            var term = $(this).data('term');
            var cityId = $(this).data('city-id');
            if (cityId && String(cityId) !== String(self.$citySelect.val())) {
                self.$citySelect.val(cityId).trigger('change');
            }
            self.$searchInput.val(term);
            self.$resetBtn.show();
            setTimeout(function () { self.performSearch(1); }, 300);
        });

        this.$suggestions.find('.fnk_hyper_search__suggestions-clear').on('click', function (e) {
            e.stopPropagation();
            self.searchHistory.clear();
            self.$suggestions.empty();
            self.$suggestions.removeClass('fnk_hyper_search__suggestions--visible');
            self.hideDropdown();
        });
    };

    HyperSearch.prototype.performSearch = function (page) {
        var self = this;
        page = page || 1;

        if (this.isLoading) {
            if (this.debug) console.warn('⏳ Search already in progress, skipping...');
            return;
        }

        if (!this.ajax) {
            if (this.debug) console.log('📤 Submitting form normally (non-AJAX)');
            this.$form.get(0).submit();
            return;
        }

        this.isLoading = true;
        this.currentPage = page;
        this.showLoader();

        var searchTerm = this.$searchInput.val() || '';

        if (searchTerm.length >= fanikarHyperSearch.min_chars) {
            var cityId = this.$citySelect.val();
            var cityName = this.$citySelect.find('option:selected').text();
            if (cityId) {
                this.searchHistory.add(searchTerm, cityId, cityName);
            }
        }

        var data = {
            action: 'fanikar_hyper_search',
            nonce: fanikarHyperSearch.nonce,
            city_id: this.$citySelect.val() || 0,
            service_id: this.$serviceSelect.val() || 0,
            search_term: searchTerm,
            mode: this.mode,
            page: page,
            items_per_page: this.itemsPerPage,
            debug: this.debug ? 'true' : 'false',
            without_city: this.$citySelect.val() ? 'false' : 'true',
            _timestamp: new Date().getTime()
        };

        if (this.debug) {
            console.log('🔍 AJAX SEARCH REQUEST (v' + VERSION + ')');
            console.log('  City ID:', data.city_id);
            console.log('  Search Term:', data.search_term);
        }

        $.ajax({
            url: fanikarHyperSearch.ajax_url,
            type: 'POST',
            data: data,
            timeout: 30000,
            dataType: 'json',
            cache: false,
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            success: function (response) {
                self.hideLoader();
                if (response.success) {
                    if (self.debug && response.data && response.data.results && response.data.results.length > 0) {
                        console.log('✅ Results found:', response.data.results.length);
                    }
                    self.renderResults(response.data);
                    self.hasMore = response.data.has_more || false;
                    self.currentPage = response.data.current_page || page;
                } else {
                    self.showError((response.data && response.data.message) || 'خطا در جستجو');
                }
            },
            error: function (xhr, status, error) {
                self.hideLoader();
                console.error('❌ AJAX ERROR:', status, error);
                var errorMessage = 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.';
                if (xhr.status === 403) {
                    errorMessage = 'خطای امنیتی. لطفاً صفحه را رفرش کنید.';
                } else if (xhr.status === 500) {
                    errorMessage = 'خطای داخلی سرور. لطفاً به مدیر سایت اطلاع دهید.';
                }
                self.showError(errorMessage);
            },
            complete: function () {
                self.isLoading = false;
            }
        });
    };

    HyperSearch.prototype.showLoader = function () {
        if (this.currentPage === 1) {
            this.$resultsInner.empty();
        }
        var loaderHTML = '<div class="fnk_hyper_search__loader-wrapper">' +
            '<div class="fnk_hyper_search__loader-spinner">' +
            '<div class="fnk_hyper_search__loader-dot fnk_hyper_search__loader-dot--1"></div>' +
            '<div class="fnk_hyper_search__loader-dot fnk_hyper_search__loader-dot--2"></div>' +
            '<div class="fnk_hyper_search__loader-dot fnk_hyper_search__loader-dot--3"></div>' +
            '</div>' +
            '<div class="fnk_hyper_search__loader-text">' + fanikarHyperSearch.strings.loading + '</div>' +
            '</div>';
        this.$loader.html(loaderHTML);
        this.$loader.show();
        this.$results.addClass('fnk_hyper_search__results--visible');
        this.showDropdown();
    };

    HyperSearch.prototype.hideLoader = function () {
        this.$loader.hide();
        this.$loader.empty();
    };

    HyperSearch.prototype.renderResults = function (data) {
        var self = this;
        var html = '';

        this.$suggestions.empty();
        this.$suggestions.removeClass('fnk_hyper_search__suggestions--visible');

        if (!data || !data.results || data.results.length === 0) {
            if (this.currentPage === 1) {
                this.$resultsInner.html(this.getNoResultsHTML());
                this.$results.addClass('fnk_hyper_search__results--visible');
                this.showDropdown();
            }
            return;
        }

        if (this.currentPage === 1) {
            this.$resultsInner.empty();
        }

        $.each(data.results, function (index, result) {
            html += self.getResultHTML(result);
        });

        this.$resultsInner.append(html);
        this.$results.addClass('fnk_hyper_search__results--visible');
        this.showDropdown();

        this.$resultsInner.find('.fnk_hyper_search__load-more').remove();

        if (this.hasMore) {
            this.$resultsInner.append($(this.getLoadMoreHTML()));
        }

        if (this.currentPage === 1 && this.$results.length > 0) {
            this.$results[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        if (this.debug) {
            console.log('✅ Results rendered successfully');
            console.log('  Total items in DOM:', this.$resultsInner.find('.fnk_hyper_search__result').length);
        }
    };

    HyperSearch.prototype.getResultHTML = function (result) {
        // Thumbnail with link
        var thumbHtml = result.thumbnail ?
            '<img src="' + result.thumbnail + '" class="fnk_hyper_search__result-thumb" alt="' + result.title + '" loading="lazy">' :
            '<div class="fnk_hyper_search__result-thumb fnk_hyper_search__result-thumb--empty"></div>';

        // Build result card - ENTIRE CARD IS A LINK
        var html = '<a href="' + result.permalink + '" class="fnk_hyper_search__result-link-wrapper">';
        html += '<div class="fnk_hyper_search__result">';

        // Thumbnail
        html += '<div class="fnk_hyper_search__result-thumb-wrap">' + thumbHtml + '</div>';

        // Content
        html += '<div class="fnk_hyper_search__result-content">';
        html += '<h3 class="fnk_hyper_search__result-title">' + result.title + '</h3>';

        if (result.search_excerpt) {
            html += '<p class="fnk_hyper_search__result-search-excerpt">' + result.search_excerpt + '</p>';
        } else if (result.excerpt) {
            html += '<p class="fnk_hyper_search__result-excerpt">' + result.excerpt + '</p>';
        }

        html += '<div class="fnk_hyper_search__result-meta">';

        if (result.city) {
            html += '<span class="fnk_hyper_search__result-meta-item">';
            html += '<span class="fnk_hyper_search__result-meta-label">شهر:</span>';
            html += '<span class="fnk_hyper_search__result-meta-value">' + result.city + '</span>';
            html += '</span>';
        }

        if (result.service) {
            html += '<span class="fnk_hyper_search__result-meta-item">';
            html += '<span class="fnk_hyper_search__result-meta-label">خدمت:</span>';
            html += '<span class="fnk_hyper_search__result-meta-value">' + result.service + '</span>';
            html += '</span>';
        }

        if (result.areas && result.areas.length > 0) {
            html += '<span class="fnk_hyper_search__result-meta-item fnk_hyper_search__result-meta-item--areas">';
            html += '<span class="fnk_hyper_search__result-meta-label">مناطق:</span>';
            html += '<span class="fnk_hyper_search__result-meta-value">';
            var areaNames = [];
            $.each(result.areas, function (i, area) {
                var displayName = area.name;
                if (area.parent) {
                    displayName = area.parent + ' - ' + area.name;
                }
                areaNames.push(displayName);
            });
            html += areaNames.join('، ');
            html += '</span>';
            html += '</span>';
        }

        html += '</div>';
        html += '</div>';
        html += '</div>';
        html += '</a>';

        return html;
    };

    HyperSearch.prototype.getNoResultsHTML = function () {
        return '<div class="fnk_hyper_search__no-results">' +
            '<span class="fnk_hyper_search__no-results-icon">🔍</span>' +
            '<h4 class="fnk_hyper_search__no-results-title">' + fanikarHyperSearch.strings.no_results + '</h4>' +
            '<p class="fnk_hyper_search__no-results-text">سعی کنید با کلمات دیگری جستجو کنید.</p>' +
            '</div>';
    };

    HyperSearch.prototype.getLoadMoreHTML = function () {
        return '<button class="fnk_hyper_search__load-more">' +
            'نمایش بیشتر <span class="fnk_hyper_search__load-more-icon">↓</span>' +
            '</button>';
    };

    HyperSearch.prototype.showError = function (message) {
        this.$resultsInner.html('<div class="fnk_hyper_search__no-results fnk_hyper_search__no-results--error">' +
            '<span class="fnk_hyper_search__no-results-icon">⚠️</span>' +
            '<h4 class="fnk_hyper_search__no-results-title">خطا</h4>' +
            '<p class="fnk_hyper_search__no-results-text">' + message + '</p>' +
            '<button class="fnk_hyper_search__retry-btn">تلاش مجدد</button>' +
            '</div>');
        this.$results.addClass('fnk_hyper_search__results--visible');
        this.showDropdown();

        this.$container.find('.fnk_hyper_search__retry-btn').on('click', function () {
            this.performSearch(1);
        }.bind(this));
    };

    HyperSearch.prototype.handleInput = function (term) {
        var self = this;

        if (this.suggestTimeout) {
            clearTimeout(this.suggestTimeout);
        }

        this.$resetBtn.toggle(term.trim().length > 0);

        if (term.trim().length === 0) {
            this.$suggestions.empty();
            this.$suggestions.removeClass('fnk_hyper_search__suggestions--visible');
            this.$resultsInner.empty();
            this.$results.removeClass('fnk_hyper_search__results--visible');
            this.hideDropdown();
            if (this.$citySelect.val()) {
                this.showRecentSearches();
            }
            return;
        }

        var cityVal = this.$citySelect.val();
        if (!cityVal) {
            this.$suggestions.removeClass('fnk_hyper_search__suggestions--visible').hide();
            return;
        }

        if (term.length < fanikarHyperSearch.min_chars) {
            this.$resultsInner.empty();
            this.$results.removeClass('fnk_hyper_search__results--visible');
            this.showRecentSearches();
            return;
        }

        this.suggestTimeout = setTimeout(function () {
            self.performSearch(1);
        }, 400);
    };

    HyperSearch.prototype.handleKeydown = function (e) {
        var $items = this.$suggestions.find('.fnk_hyper_search__suggestion:not(.fnk_hyper_search__suggestion--history)');
        var $active = $items.filter('.fnk_hyper_search__suggestion--active');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if ($active.length === 0) {
                $items.first().addClass('fnk_hyper_search__suggestion--active');
            } else {
                $active.removeClass('fnk_hyper_search__suggestion--active');
                var $next = $active.next();
                if ($next.length === 0) {
                    $items.first().addClass('fnk_hyper_search__suggestion--active');
                } else {
                    $next.addClass('fnk_hyper_search__suggestion--active');
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if ($active.length === 0) {
                $items.last().addClass('fnk_hyper_search__suggestion--active');
            } else {
                $active.removeClass('fnk_hyper_search__suggestion--active');
                var $prev = $active.prev();
                if ($prev.length === 0) {
                    $items.last().addClass('fnk_hyper_search__suggestion--active');
                } else {
                    $prev.addClass('fnk_hyper_search__suggestion--active');
                }
            }
        } else if (e.key === 'Escape') {
            this.hideDropdown();
        }
    };

    // ============================================================
    // INITIALIZE
    // ============================================================
    $(document).ready(function () {
        console.log('🚀 Fanikara Hyper Search v' + VERSION + ' loading...');

        $('.fnk_hyper_search').each(function () {
            if (!$(this).data('hyper-search-initialized')) {
                new HyperSearch(this);
                $(this).data('hyper-search-initialized', true);
            }
        });
    });

})(jQuery);