(function () {
  if (window.FKQuickAccessInitialized) return;
  window.FKQuickAccessInitialized = true;
  'use strict';

  var offsetGap = 16;
  var getTopOffset = function () {
    var value = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--wp-admin--admin-bar--height'));
    return Number.isFinite(value) ? value : 0;
  };

  var getScrollOffset = function (link) {
    var wrapper = link.closest('.fk-quickaccess-wrap');
    var wrapperHeight = wrapper ? wrapper.getBoundingClientRect().height : 0;
    return getTopOffset() + wrapperHeight + offsetGap;
  };

  document.addEventListener('click', function (event) {
    var link = event.target.closest('[data-fk-target]');
    if (!link) return;
    var target = document.getElementById(link.getAttribute('data-fk-target'));
    if (!target) return;
    event.preventDefault();
    var nav = link.closest('.fk-quickaccess');
    if (nav) {
      nav.setAttribute('data-fk-clicked-target', link.getAttribute('data-fk-target'));
      nav.querySelectorAll('[data-fk-target]').forEach(function (item) {
        item.classList.toggle('is-active', item === link);
      });
    }
    window.scrollTo({ top: Math.max(0, target.getBoundingClientRect().top + window.pageYOffset - getScrollOffset(link)), behavior: 'smooth' });
    if (window.history && window.history.replaceState) window.history.replaceState(null, '', '#' + link.getAttribute('data-fk-target'));
    var stickyWrap = link.closest('.fk-quickaccess-wrap--sticky');
    if (stickyWrap && stickyWrap.getBoundingClientRect().top <= 2) {
      stickyWrap.classList.add('is-stuck');
      window.dispatchEvent(new Event('scroll'));
    }
  });

  document.querySelectorAll('.fk-quickaccess').forEach(function (nav) {
    var links = Array.prototype.slice.call(nav.querySelectorAll('[data-fk-target]'));
    if (!links.length) return;
    var updateActive = function () {
      var trigger = nav.getBoundingClientRect().bottom + 8;
      var activeId = '';
      var activeTop = -Infinity;
      links.forEach(function (link) {
        var target = document.getElementById(link.getAttribute('data-fk-target'));
        if (!target) return;
        var rect = target.getBoundingClientRect();
        /* Treat the target as the start of its whole section. This keeps the
         * item active while scrolling through the target's content and all
         * of its descendants, until the next configured target is reached. */
        if (rect.top <= trigger + offsetGap && rect.top > activeTop) {
          activeId = target.id;
          activeTop = rect.top;
        }
      });
      var clickedId = nav.getAttribute('data-fk-clicked-target');
      if (clickedId) {
        var clickedTarget = document.getElementById(clickedId);
        if (clickedTarget && clickedTarget.getBoundingClientRect().bottom > trigger) {
          activeId = clickedId;
        } else {
          nav.removeAttribute('data-fk-clicked-target');
        }
      }
      links.forEach(function (link) { link.classList.toggle('is-active', link.getAttribute('data-fk-target') === activeId); });
    };
    window.addEventListener('scroll', updateActive, { passive: true });
    window.addEventListener('resize', updateActive);
    updateActive();
  });

  document.querySelectorAll('.fk-quickaccess-wrap--sticky').forEach(function (section) {
    var placeholder = document.createElement('div');
    placeholder.className = 'fk-quickaccess-sticky-placeholder';
    section.parentNode.insertBefore(placeholder, section);
    var originalParent = section.parentNode;
    var stuck = false;
    var alignedToContainer = false;
    var alignToContainer = function () {
      if (stuck) return;
      var container = section.closest('.e-con-inner, .elementor-container, .container, .ast-container');
      if (!container || container === originalParent) {
        if (alignedToContainer) {
          section.style.width = '';
          section.style.maxWidth = '';
          section.style.left = '';
          section.style.marginLeft = '';
          section.style.marginRight = '';
        }
        alignedToContainer = false;
        return;
      }
      var containerRect = container.getBoundingClientRect();
      var parentRect = originalParent.getBoundingClientRect();
      if (!containerRect.width || !parentRect.width) return;
      section.style.width = Math.min(containerRect.width, window.innerWidth) + 'px';
      section.style.maxWidth = '100vw';
      section.style.left = (containerRect.left - parentRect.left) + 'px';
      section.style.marginLeft = '0';
      section.style.marginRight = '0';
      alignedToContainer = true;
    };
    alignToContainer();
    var anchorTop = placeholder.getBoundingClientRect().top + window.pageYOffset;
    var setStuck = function (value) {
      if (value === stuck) return;
      stuck = value;
      if (stuck) {
        var rect = section.getBoundingClientRect();
        placeholder.style.height = rect.height + 'px';
        placeholder.style.width = '100%';
        placeholder.classList.add('is-active');
        section.style.setProperty('--fk-quickaccess-container-width', Math.round(rect.width) + 'px');
        document.body.appendChild(section);
        section.style.width = 'auto';
        section.style.left = '0';
        section.style.right = '0';
        section.style.transform = 'none';
        section.classList.add('is-stuck');
      } else {
        placeholder.classList.remove('is-active');
        placeholder.style.height = '';
        placeholder.style.width = '';
        section.style.width = '';
        section.style.maxWidth = '';
        section.style.left = '';
        section.style.right = '';
        section.style.transform = '';
        section.style.marginLeft = '';
        section.style.marginRight = '';
        section.style.removeProperty('--fk-quickaccess-container-width');
        section.classList.remove('is-stuck');
        if (placeholder.parentNode === originalParent) {
          originalParent.insertBefore(section, placeholder.nextSibling);
        }
        alignToContainer();
      }
    };
    var update = function () {
      if (!stuck) anchorTop = placeholder.getBoundingClientRect().top + window.pageYOffset;
      var shouldStick = window.pageYOffset >= anchorTop;
      setStuck(shouldStick);
    };
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', function () {
      if (stuck) { setStuck(false); }
      alignToContainer();
      anchorTop = placeholder.getBoundingClientRect().top + window.pageYOffset;
      update();
    });
    update();
  });

}());
