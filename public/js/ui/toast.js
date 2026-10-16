let timer = 0;

/** Brief status message, announced to screen readers. */
export function toast(message, { error = false } = {}) {
  const element = document.getElementById('toast');
  if (!element) return;
  element.textContent = message;
  element.classList.toggle('toast--error', error);
  element.hidden = false;
  clearTimeout(timer);
  timer = setTimeout(() => { element.hidden = true; }, error ? 8000 : 4500);
}
