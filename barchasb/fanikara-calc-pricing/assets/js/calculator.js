(function () {
  "use strict";

  const config = window.FCP_DATA || {};

  function initCalculator(root) {
    const form = root.querySelector(".fcp-price-form");
    const estimatedPrice = root.querySelector(".fcp-price-preview-value");
    const pricePreview = root.querySelector(".fcp-price-preview");
    const outOfCityCheckbox = root.querySelector('[data-fcp-field="out_of_city"]');
    const transportationCostField = root.querySelector('[data-fcp-field="transportation_cost"]');
    const specialToggleButton = root.querySelector(".fcp-special-toggle-button");
    const specialToggleContent = root.querySelector(".fcp-special-toggle-content");
    const serviceTimeField = root.querySelector('[data-fcp-field="service_time"]');

    const digitMap = {
      "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4", "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
      "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4", "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9"
    };

    function setSpecialToggle(open) {
      if (!specialToggleButton || !specialToggleContent) return;
      specialToggleButton.setAttribute("aria-expanded", open ? "true" : "false");
      specialToggleContent.hidden = !open;
    }

    function updateTransportationField() {
      const isOutOfCity = outOfCityCheckbox.checked;
      transportationCostField.disabled = !isOutOfCity;
      if (!isOutOfCity) transportationCostField.value = "";
    }

    function normalizeDigits(value) {
      return String(value ?? "").split("").map(ch => digitMap[ch] ?? ch).join("");
    }

    function getRawNumber(value) {
      const cleaned = normalizeDigits(value).replace(/[^\d]/g, "");
      const num = Number(cleaned);
      return Number.isFinite(num) ? num : 0;
    }

    function formatPrice(value) {
      return Math.round(value).toLocaleString("en-US");
    }

    function formatNumericInput(input) {
      const rawValue = getRawNumber(input.value);
      input.value = rawValue ? String(rawValue) : "";
    }

    function sanitizeNumericInput(input) {
      // اعداد فارسی/عربی به انگلیسی تبدیل و هر کاراکتر غیرعددی حذف می‌شود.
      input.value = normalizeDigits(input.value).replace(/[^\d]/g, "");
      formatNumericInput(input);
    }

    function getValue(fieldName) {
      const field = root.querySelector('[data-fcp-field="' + fieldName + '"]');
      return field ? field.value : "";
    }

    function getFormValues() {
      return {
        serviceType: getValue("service_type"),
        springLength: getRawNumber(getValue("spring_length")),
        springDiameter: getRawNumber(getValue("spring_diameter")),
        serviceDuration: getRawNumber(getValue("service_duration")),
        serviceTime: Number(getValue("service_time") || 1),
        technicianCount: getRawNumber(getValue("technician_count")),
        officialInvoiceWithVat: root.querySelector('[data-fcp-field="official_invoice_with_vat"]').checked,
        equipmentUsed: getValue("equipment_used"),
        problemCause: getValue("problem_cause"),
        transportationCost: outOfCityCheckbox.checked ? getRawNumber(transportationCostField.value) : 0,
        tip: getRawNumber(getValue("tip")),
        equipmentRentalCost: getRawNumber(getValue("equipment_rental_cost")),
        equipmentTransportCost: getRawNumber(getValue("equipment_transport_cost"))
      };
    }

    function calculateFinalPrice(values) {
      if (values.serviceType === "construction_excavation") {
        return config.masonryCost;
      }

      const isNegotiable =
        values.equipmentUsed !== "normal_spring" ||
        values.springLength > 5 ||
        values.serviceType === "other" ||
        (values.problemCause !== "fecal_blockage" && values.problemCause !== "soft_spongy_objects");

      if (isNegotiable) {
        return config.negotiablePrice;
      }

      let taxMultiplier = 1;

      if (values.officialInvoiceWithVat === true) {
        taxMultiplier *= 1 + (Number(config.vatRate || 0) / 100);
      }

      let finalPriceMin =
        ((values.springDiameter - 2) / 10) *
        Number(config.springCostPerMeter || 0) *
        values.springLength;

      finalPriceMin += Number(config.serviceCostPerMinutePerPerson || 0) * values.serviceDuration;
      finalPriceMin *= values.serviceTime;
      finalPriceMin *= values.technicianCount;
      finalPriceMin += values.transportationCost;
      finalPriceMin += values.equipmentRentalCost;
      finalPriceMin += values.equipmentTransportCost;
      finalPriceMin *= taxMultiplier;
      finalPriceMin += values.tip;

      const finalPriceMax = finalPriceMin * 1.25;
      return "حدودا بین " + formatPrice(finalPriceMin) + " تا " + formatPrice(finalPriceMax) + " تومان";
    }

    function recalculatePrice() {
      estimatedPrice.textContent = calculateFinalPrice(getFormValues());
      pricePreview.classList.remove("is-error");
    }

    if (specialToggleButton) {
      specialToggleButton.addEventListener("click", () => {
        const isOpen = specialToggleButton.getAttribute("aria-expanded") === "true";
        setSpecialToggle(!isOpen);
      });
    }

    outOfCityCheckbox.addEventListener("change", () => {
      updateTransportationField();
      recalculatePrice();
    });

    form.querySelectorAll("select, input").forEach(field => {
      const eventName = field.tagName === "SELECT" || field.type === "checkbox" ? "change" : "input";
      field.addEventListener(eventName, () => {
        if (field.matches("[data-numeric-input]")) sanitizeNumericInput(field);
        recalculatePrice();
      });
    });

    form.addEventListener("submit", event => {
      event.preventDefault();
      recalculatePrice();
    });

    const serviceTimeValues = Array.isArray(config.serviceTimeValues) ? config.serviceTimeValues : [1, 1.4, 1.3, 1.82];
    const serviceTimeOptions = Array.from(serviceTimeField.options).filter(option => option.value !== "");
    serviceTimeOptions.forEach((option, index) => {
      if (serviceTimeValues[index] !== undefined) option.value = String(serviceTimeValues[index]);
    });

    if (!serviceTimeField.value && serviceTimeOptions.length) serviceTimeOptions[0].selected = true;
    setSpecialToggle(false);
    updateTransportationField();
    recalculatePrice();
  }

  document.querySelectorAll(".fcp-calculator").forEach(initCalculator);
}());
