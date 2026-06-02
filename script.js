/* TMG · черновая интерактивность */
(() => {
  // Момент загрузки страницы — используется как нижняя граница времени до сабмита.
  // Если человек заполняет форму, до сабмита проходят секунды; бот шлёт мгновенно.
  const PAGE_LOAD_TS = Date.now();

  // ---------- Burger / Mobile menu ----------
  const burger = document.querySelector(".header__burger");
  const mobileMenu = document.getElementById("mobile-menu");
  function openBurger() {
    if (!mobileMenu) return;
    mobileMenu.removeAttribute("hidden");
    if (burger) burger.setAttribute("aria-expanded", "true");
    document.body.style.overflow = "hidden";
  }
  function closeBurger() {
    if (!mobileMenu) return;
    mobileMenu.setAttribute("hidden", "");
    if (burger) burger.setAttribute("aria-expanded", "false");
    document.body.style.overflow = "";
  }
  if (burger) burger.addEventListener("click", openBurger);
  document.querySelectorAll("[data-burger-close]").forEach(el =>
    el.addEventListener("click", closeBurger)
  );
  document.addEventListener("keydown", e => {
    if (e.key === "Escape" && mobileMenu && !mobileMenu.hasAttribute("hidden")) closeBurger();
  });

  // ---------- Popup ----------
  const popups = document.querySelectorAll(".popup");
  const openers = document.querySelectorAll("[data-popup]");
  const closers = document.querySelectorAll("[data-close]");

  function openPopup(id, productName) {
    const popup = document.getElementById(id);
    if (!popup) return;
    popup.removeAttribute("hidden");
    document.body.style.overflow = "hidden";
    if (id === "popup-3" && productName) {
      const title = document.getElementById("popup-3-title");
      if (title) title.textContent = `Заявка по ${productName}`;
      const productField = document.getElementById("popup-3-product");
      if (productField) productField.value = productName;
    }
    if (id === "popup-order" && productName) {
      const title = document.getElementById("popup-order-title");
      if (title) title.textContent = `Заказать СОЖ TAIKYUU-X ${productName}`;
      const productField = document.getElementById("popup-order-product");
      if (productField) productField.value = productName;
    }
  }
  function closeAll() {
    popups.forEach(p => p.setAttribute("hidden", ""));
    document.body.style.overflow = "";
  }

  openers.forEach(btn => {
    btn.addEventListener("click", () => {
      const id = btn.getAttribute("data-popup");
      // product берём с самой кнопки (если есть), иначе с ближайшей карточки .product
      const card = btn.closest(".product");
      const productName = btn.getAttribute("data-product")
        || (card ? card.getAttribute("data-product") : null);
      openPopup(id, productName);
    });
  });
  closers.forEach(c => c.addEventListener("click", closeAll));
  document.addEventListener("keydown", e => { if (e.key === "Escape") closeAll(); });

  // ---------- Product cards accordion ----------
  const cards = document.querySelectorAll(".product");
  const panels = document.querySelectorAll(".product-panel");

  function closeAllProducts() {
    cards.forEach(c => {
      c.classList.remove("is-open");
      const t = c.querySelector(".product__more");
      if (t) t.textContent = "Читать подробнее →";
    });
    panels.forEach(p => p.setAttribute("hidden", ""));
  }

  const panelFromClass = {
    CutMax:   "product-panel--from-CutMax",
    AquaMax:  "product-panel--from-AquaMax",
    AquaLite: "product-panel--from-AquaLite",
  };
  const allPanelFromClasses = Object.values(panelFromClass);

  // Сохраняем "штатное" место панелей (чтобы возвращать на десктопе)
  const panelsHome = document.querySelector(".areas__inner");
  const areasHelp = document.querySelector(".areas__help");
  const isMobileViewport = () => window.matchMedia("(max-width: 1024px)").matches;

  function placePanelInDom(panel, card) {
    if (isMobileViewport()) {
      // Mobile: панель прямо после кликнутой карточки
      card.insertAdjacentElement("afterend", panel);
    } else {
      // Desktop: возвращаем перед .areas__help (или в конец .areas__inner)
      if (panelsHome && areasHelp && areasHelp.parentElement === panelsHome) {
        panelsHome.insertBefore(panel, areasHelp);
      } else if (panelsHome) {
        panelsHome.appendChild(panel);
      }
    }
  }

  cards.forEach(card => {
    card.addEventListener("click", (e) => {
      // Не реагируем на клики по ссылкам и popup-кнопкам внутри карточки
      if (e.target.closest("a, [data-popup]")) return;

      const toggle = card.querySelector(".product__more");
      const isOpen = card.classList.contains("is-open");
      const productName = card.getAttribute("data-product");
      const panel = document.getElementById(`details-${productName}`);
      closeAllProducts();
      if (!isOpen) {
        card.classList.add("is-open");
        if (toggle) toggle.textContent = "Свернуть ↑";
        if (panel) {
          placePanelInDom(panel, card);
          panel.removeAttribute("hidden");
          allPanelFromClasses.forEach(c => panel.classList.remove(c));
          const fromClass = panelFromClass[productName];
          if (fromClass) panel.classList.add(fromClass);
          panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
      }
    });
  });

  // На resize — если есть открытая карточка, переместить панель в нужное место
  let resizeTimer;
  window.addEventListener("resize", () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      const openCard = document.querySelector(".product.is-open");
      if (!openCard) return;
      const productName = openCard.getAttribute("data-product");
      const panel = document.getElementById(`details-${productName}`);
      if (panel && !panel.hasAttribute("hidden")) {
        placePanelInDom(panel, openCard);
      }
    }, 150);
  });

  // ---------- PDn checkbox → submit button enable/disable ----------
  document.querySelectorAll("[data-pdn]").forEach(checkbox => {
    const form = checkbox.closest("form");
    if (!form) return;
    const submitBtn = form.querySelector("[data-pdn-submit]");
    if (!submitBtn) return;
    const update = () => {
      if (checkbox.checked) {
        submitBtn.removeAttribute("disabled");
        submitBtn.removeAttribute("aria-disabled");
      } else {
        submitBtn.setAttribute("disabled", "");
        submitBtn.setAttribute("aria-disabled", "true");
      }
    };
    update();
    checkbox.addEventListener("change", update);
  });

  // ---------- Form submit → send.php → Я.Почта ----------
  function showStatus(form, type, text) {
    const box = form.querySelector("[data-form-status]");
    if (!box) return;
    box.className = "form-status form-status--" + type;
    box.textContent = text;
    box.removeAttribute("hidden");
  }
  function clearStatus(form) {
    const box = form.querySelector("[data-form-status]");
    if (box) {
      box.setAttribute("hidden", "");
      box.textContent = "";
    }
  }

  document.querySelectorAll("form[data-form]").forEach(form => {
    form.addEventListener("submit", async e => {
      e.preventDefault();

      const submitBtn = form.querySelector("[data-pdn-submit], button[type='submit']");
      if (!submitBtn) return;
      // Защита от двойного клика
      if (submitBtn.dataset.sending === "1") return;

      const originalText = submitBtn.textContent;
      submitBtn.dataset.sending = "1";
      submitBtn.setAttribute("disabled", "");
      submitBtn.textContent = "Отправляется…";
      clearStatus(form);

      // Собираем поля
      const fd = new FormData(form);
      const payload = {};
      fd.forEach((v, k) => { payload[k] = typeof v === "string" ? v : ""; });
      // Время от загрузки страницы до сабмита — отсекает мгновенные ботские POST'ы
      payload._t_open_ms = Date.now() - PAGE_LOAD_TS;

      try {
        const res = await fetch("/send.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });
        const json = await res.json().catch(() => ({}));

        if (res.ok && json.ok) {
          // Цель Я.Метрики только при УСПЕШНОЙ отправке
          if (typeof ym === "function") {
            ym(109044452, "reachGoal", "submit");
          }
          showStatus(form, "success", "Спасибо! Заявка отправлена. Мы свяжемся с вами в течение часа.");
          form.reset();
          // PDn-чекбокс снят — вернуть кнопку в disabled через update()
          form.querySelectorAll("[data-pdn]").forEach(cb => cb.dispatchEvent(new Event("change")));
          // Через 4 сек закрыть попап (если внутри попапа)
          setTimeout(() => {
            const popup = form.closest(".popup");
            if (popup) {
              popup.setAttribute("hidden", "");
              document.body.style.overflow = "";
              clearStatus(form);
            }
          }, 4000);
        } else {
          const errMap = {
            phone_required: "Укажите телефон.",
            email_required: "Укажите почту.",
            email_invalid:  "Неверный формат почты.",
            rate_limit:     "Слишком много заявок. Попробуйте через минуту.",
            send_failed:    "Не удалось отправить. Попробуйте позже или позвоните 8 (800) 222-62-88.",
          };
          showStatus(form, "error", errMap[json.error] || "Не удалось отправить. Попробуйте позже.");
        }
      } catch (err) {
        showStatus(form, "error", "Сеть недоступна. Попробуйте позже или позвоните 8 (800) 222-62-88.");
      } finally {
        submitBtn.textContent = originalText;
        delete submitBtn.dataset.sending;
        // disabled-состояние пересчитается через PDn change-event выше (при успехе)
        // или вручную здесь (при ошибке) — снимаем disabled, чтобы можно было повторить
        const checkbox = form.querySelector("[data-pdn]");
        if (checkbox && checkbox.checked) {
          submitBtn.removeAttribute("disabled");
        }
      }
    });
  });

  // ---------- FAQ: tabs + accordion ----------
  (function initFAQ() {
    const root = document.querySelector(".faq");
    if (!root) return;

    // Tabs
    const tabs = root.querySelectorAll("[data-faq-tab]");
    const panels = root.querySelectorAll("[data-faq-panel]");
    tabs.forEach(tab => {
      tab.addEventListener("click", () => {
        const id = tab.dataset.faqTab;
        tabs.forEach(t => {
          const on = t === tab;
          t.classList.toggle("is-active", on);
          t.setAttribute("aria-selected", on ? "true" : "false");
        });
        panels.forEach(p => {
          if (p.dataset.faqPanel === id) p.removeAttribute("hidden");
          else p.setAttribute("hidden", "");
        });
      });
    });

    // Accordion
    root.querySelectorAll(".faq__question").forEach(btn => {
      btn.addEventListener("click", () => {
        const item = btn.closest(".faq__item");
        const answer = item.querySelector(".faq__answer");
        const open = item.classList.toggle("is-open");
        btn.setAttribute("aria-expanded", open ? "true" : "false");
        if (open) answer.removeAttribute("hidden");
        else answer.setAttribute("hidden", "");
      });
    });
  })();

  // ---------- Cookie consent bar ----------
  (function initCookieBar() {
    const KEY = "tmg_cookies_consent_v1";
    const bar = document.querySelector("[data-cookie-bar]");
    if (!bar) return;
    let accepted = false;
    try { accepted = localStorage.getItem(KEY) === "1"; } catch (_) {}
    if (accepted) return;
    bar.removeAttribute("hidden");
    const btn = bar.querySelector("[data-cookie-accept]");
    if (btn) {
      btn.addEventListener("click", () => {
        try { localStorage.setItem(KEY, "1"); } catch (_) {}
        bar.setAttribute("hidden", "");
      });
    }
  })();
})();
