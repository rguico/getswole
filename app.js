const userSelect = document.getElementById("user-select");
const app = document.getElementById("app");

userSelect.addEventListener("change", () => {
  const user = userSelect.value;
  app.textContent = user ? `Selected: ${user}` : "";
});
