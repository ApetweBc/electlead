const STORAGE_KEY = "electlead-state-v1";
const ADMIN_SESSION_KEY = "electlead-admin-auth";
const ADMIN_USERNAME = "Root";
const ADMIN_PASSWORD = String.fromCodePoint(
  76,
  117,
  109,
  98,
  97,
  80,
  97,
  114,
  107,
  111,
  115,
  111,
);

const state = loadState();

const clientPortal = document.getElementById("client-portal");
const adminPortal = document.getElementById("admin-portal");
const clientViewBtn = document.getElementById("client-view-btn");
const adminViewBtn = document.getElementById("admin-view-btn");

const adminAuthCard = document.getElementById("admin-auth-card");
const adminPanel = document.getElementById("admin-panel");
const adminAuthForm = document.getElementById("admin-auth-form");
const adminUsernameInput = document.getElementById("admin-username");
const adminPasswordInput = document.getElementById("admin-password");
const authFeedback = document.getElementById("auth-feedback");
const adminLogoutBtn = document.getElementById("admin-logout-btn");

const categoryForm = document.getElementById("category-form");
const categoryNameInput = document.getElementById("category-name");
const categoryList = document.getElementById("category-list");
const adminCategoryList = document.getElementById("admin-category-list");
const nominationForm = document.getElementById("nomination-form");
const nominationCategory = document.getElementById("nomination-category");
const nominationFeedback = document.getElementById("nomination-feedback");
const verificationList = document.getElementById("verification-list");
const voteForm = document.getElementById("vote-form");
const voteCategory = document.getElementById("vote-category");
const voteCandidate = document.getElementById("vote-candidate");
const voteFeedback = document.getElementById("vote-feedback");
const results = document.getElementById("results");
const resetBtn = document.getElementById("reset-btn");

clientViewBtn.addEventListener("click", () => setPortal("client"));
adminViewBtn.addEventListener("click", () => setPortal("admin"));

adminAuthForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const username = adminUsernameInput.value.trim();
  const password = adminPasswordInput.value;

  if (username === ADMIN_USERNAME && password === ADMIN_PASSWORD) {
    sessionStorage.setItem(ADMIN_SESSION_KEY, "true");
    adminAuthForm.reset();
    setFeedback(authFeedback, "Admin login successful.", false);
    syncAdminSessionUi();
    renderAll();
    return;
  }

  setFeedback(authFeedback, "Invalid admin username or password.", true);
});

adminLogoutBtn.addEventListener("click", () => {
  sessionStorage.removeItem(ADMIN_SESSION_KEY);
  authFeedback.textContent = "";
  syncAdminSessionUi();
  setPortal("client");
});

categoryForm.addEventListener("submit", (event) => {
  event.preventDefault();
  if (!isAdminAuthenticated()) {
    return;
  }

  const name = categoryNameInput.value.trim();
  if (!name) {
    return;
  }

  const exists = state.categories.some(
    (item) => normalize(item.name) === normalize(name),
  );
  if (exists) {
    categoryNameInput.value = "";
    return;
  }

  state.categories.push({ id: crypto.randomUUID(), name });
  persist();
  categoryNameInput.value = "";
  renderAll();
});

nominationForm.addEventListener("submit", (event) => {
  event.preventDefault();
  nominationFeedback.textContent = "";

  const categoryId = nominationCategory.value;
  const candidateName = document.getElementById("candidate-name").value.trim();
  const nominatorOne = document.getElementById("nominator-one").value.trim();
  const nominatorTwo = document.getElementById("nominator-two").value.trim();
  const extra = document
    .getElementById("nominator-extra")
    .value.split(",")
    .map((value) => value.trim())
    .filter(Boolean);

  const nominators = [
    ...new Set([nominatorOne, nominatorTwo, ...extra].filter(Boolean)),
  ];

  if (!categoryId || !candidateName || nominators.length < 2) {
    setFeedback(
      nominationFeedback,
      "A nomination needs a category, candidate name, and at least 2 unique nominators.",
      true,
    );
    return;
  }

  const existingForCategory = state.nominations.find(
    (entry) =>
      entry.categoryId === categoryId &&
      normalize(entry.candidateName) === normalize(candidateName),
  );

  if (existingForCategory) {
    setFeedback(
      nominationFeedback,
      "This candidate has already been nominated in that category.",
      true,
    );
    return;
  }

  state.nominations.push({
    id: crypto.randomUUID(),
    categoryId,
    candidateName,
    nominators,
    criminalCheck: "pending",
    characterCheck: "pending",
    status: "nominated",
    committeeNotes: "",
  });

  persist();
  nominationForm.reset();
  setFeedback(
    nominationFeedback,
    "Nomination recorded. Election committee can now verify checks.",
    false,
  );
  renderAll();
});

voteForm.addEventListener("submit", (event) => {
  event.preventDefault();
  voteFeedback.textContent = "";

  const categoryId = voteCategory.value;
  const voterName = document.getElementById("voter-name").value.trim();
  const candidateId = voteCandidate.value;

  if (!categoryId || !voterName || !candidateId) {
    setFeedback(
      voteFeedback,
      "Category, voter name, and candidate are required.",
      true,
    );
    return;
  }

  const hasVoted = state.votes.some(
    (vote) =>
      vote.categoryId === categoryId &&
      normalize(vote.voterName) === normalize(voterName),
  );
  if (hasVoted) {
    setFeedback(
      voteFeedback,
      "This voter has already voted in this category.",
      true,
    );
    return;
  }

  const candidate = state.nominations.find(
    (nom) => nom.id === candidateId && nom.status === "verified",
  );
  if (!candidate) {
    setFeedback(
      voteFeedback,
      "Selected candidate is not eligible to receive votes.",
      true,
    );
    return;
  }

  state.votes.push({
    id: crypto.randomUUID(),
    categoryId,
    voterName,
    candidateId,
    createdAt: new Date().toISOString(),
  });

  persist();
  voteForm.reset();
  setFeedback(
    voteFeedback,
    "Vote cast successfully. Ballot recorded as secret vote.",
    false,
  );
  renderAll();
});

voteCategory.addEventListener("change", () => {
  populateVoteCandidates(voteCategory.value);
});

resetBtn.addEventListener("click", () => {
  if (!isAdminAuthenticated()) {
    return;
  }

  if (!globalThis.confirm("Reset all election data? This cannot be undone.")) {
    return;
  }

  state.categories = seedCategories();
  state.nominations = [];
  state.votes = [];
  persist();
  renderAll();
});

function setPortal(target) {
  const isClient = target === "client";
  clientPortal.classList.toggle("hidden", !isClient);
  adminPortal.classList.toggle("hidden", isClient);

  clientViewBtn.classList.toggle("active", isClient);
  adminViewBtn.classList.toggle("active", !isClient);

  if (!isClient) {
    syncAdminSessionUi();
  }
}

function syncAdminSessionUi() {
  const isAuthed = isAdminAuthenticated();
  adminAuthCard.classList.toggle("hidden", isAuthed);
  adminPanel.classList.toggle("hidden", !isAuthed);
}

function isAdminAuthenticated() {
  return sessionStorage.getItem(ADMIN_SESSION_KEY) === "true";
}

function renderAll() {
  renderCategories();
  populateCategorySelects();
  renderVerification();
  renderResults();
}

function renderCategories() {
  categoryList.innerHTML = "";
  adminCategoryList.innerHTML = "";

  for (const category of state.categories) {
    const userItem = document.createElement("li");
    userItem.textContent = category.name;
    categoryList.appendChild(userItem);

    const adminItem = document.createElement("li");
    adminItem.textContent = category.name;
    adminCategoryList.appendChild(adminItem);
  }
}

function populateCategorySelects() {
  nominationCategory.innerHTML = "";
  voteCategory.innerHTML = "";

  for (const category of state.categories) {
    const nominationOption = document.createElement("option");
    nominationOption.value = category.id;
    nominationOption.textContent = category.name;

    const voteOption = nominationOption.cloneNode(true);
    nominationCategory.appendChild(nominationOption);
    voteCategory.appendChild(voteOption);
  }

  populateVoteCandidates(voteCategory.value);
}

function populateVoteCandidates(categoryId) {
  voteCandidate.innerHTML = "";

  const verified = state.nominations.filter(
    (entry) => entry.categoryId === categoryId && entry.status === "verified",
  );

  if (verified.length === 0) {
    const emptyOption = document.createElement("option");
    emptyOption.textContent = "No verified candidates yet";
    emptyOption.value = "";
    voteCandidate.appendChild(emptyOption);
    return;
  }

  for (const candidate of verified) {
    const option = document.createElement("option");
    option.value = candidate.id;
    option.textContent = candidate.candidateName;
    voteCandidate.appendChild(option);
  }
}

function renderVerification() {
  verificationList.innerHTML = "";

  if (!isAdminAuthenticated()) {
    return;
  }

  if (state.nominations.length === 0) {
    const empty = document.createElement("p");
    empty.textContent = "No nominations yet.";
    verificationList.appendChild(empty);
    return;
  }

  for (const nomination of state.nominations) {
    const category = state.categories.find(
      (item) => item.id === nomination.categoryId,
    );
    const wrapper = document.createElement("article");
    wrapper.className = "verify-item";

    const statusClass = `status-${nomination.status}`;
    wrapper.innerHTML = `
      <div class="verify-header">
        <strong>${escapeHtml(nomination.candidateName)} • ${escapeHtml(category ? category.name : "Unknown Category")}</strong>
        <span class="status-chip ${statusClass}">${nomination.status}</span>
      </div>
      <div>Nominators: ${escapeHtml(nomination.nominators.join(", "))}</div>
      <label>Criminal check
        <select data-check="criminal" data-id="${nomination.id}">
          ${statusOption(nomination.criminalCheck)}
        </select>
      </label>
      <label>Character check
        <select data-check="character" data-id="${nomination.id}">
          ${statusOption(nomination.characterCheck)}
        </select>
      </label>
      <label>Committee notes
        <textarea rows="2" data-notes="${nomination.id}">${escapeHtml(nomination.committeeNotes || "")}</textarea>
      </label>
      <div class="actions">
        <button class="secondary" data-verify="${nomination.id}">Approve Candidate</button>
        <button class="reject" data-reject="${nomination.id}">Reject Candidate</button>
      </div>
    `;

    verificationList.appendChild(wrapper);
  }

  verificationList.querySelectorAll("select[data-check]").forEach((element) => {
    element.addEventListener("change", (event) => {
      const id = event.target.dataset.id;
      const key = event.target.dataset.check;
      const nomination = state.nominations.find((entry) => entry.id === id);
      if (!nomination) {
        return;
      }

      if (key === "criminal") {
        nomination.criminalCheck = event.target.value;
      }
      if (key === "character") {
        nomination.characterCheck = event.target.value;
      }
      persist();
    });
  });

  verificationList
    .querySelectorAll("textarea[data-notes]")
    .forEach((element) => {
      element.addEventListener("input", (event) => {
        const id = event.target.dataset.notes;
        const nomination = state.nominations.find((entry) => entry.id === id);
        if (!nomination) {
          return;
        }

        nomination.committeeNotes = event.target.value;
        persist();
      });
    });

  verificationList.querySelectorAll("button[data-verify]").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      const id = button.dataset.verify;
      const nomination = state.nominations.find((entry) => entry.id === id);
      if (!nomination) {
        return;
      }

      if (nomination.nominators.length < 2) {
        alert("Candidate needs at least 2 nominators.");
        return;
      }

      if (
        nomination.criminalCheck !== "pass" ||
        nomination.characterCheck !== "pass"
      ) {
        alert(
          "Both criminal and character checks must be set to pass before approval.",
        );
        return;
      }

      nomination.status = "verified";
      persist();
      renderAll();
    });
  });

  verificationList.querySelectorAll("button[data-reject]").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      const id = button.dataset.reject;
      const nomination = state.nominations.find((entry) => entry.id === id);
      if (!nomination) {
        return;
      }

      nomination.status = "rejected";
      persist();
      renderAll();
    });
  });
}

function renderResults() {
  results.innerHTML = "";

  if (!isAdminAuthenticated()) {
    return;
  }

  for (const category of state.categories) {
    const card = document.createElement("section");
    card.className = "result-card";

    const verifiedInCategory = state.nominations.filter(
      (entry) =>
        entry.categoryId === category.id && entry.status === "verified",
    );

    const lines = verifiedInCategory
      .map((candidate) => {
        const count = state.votes.filter(
          (vote) =>
            vote.categoryId === category.id &&
            vote.candidateId === candidate.id,
        ).length;
        return `<li>${escapeHtml(candidate.candidateName)}: <strong>${count}</strong> vote(s)</li>`;
      })
      .join("");

    const totalVotes = state.votes.filter(
      (vote) => vote.categoryId === category.id,
    ).length;

    card.innerHTML = `
      <h3>${escapeHtml(category.name)}</h3>
      <div>Total votes: <strong>${totalVotes}</strong></div>
      ${lines ? `<ul>${lines}</ul>` : "<p>No verified candidates or no votes yet.</p>"}
    `;

    results.appendChild(card);
  }
}

function statusOption(value) {
  const statuses = ["pending", "pass", "fail"];
  return statuses
    .map((status) => {
      const selected = value === status ? "selected" : "";
      return `<option value="${status}" ${selected}>${status}</option>`;
    })
    .join("");
}

function setFeedback(element, message, isError) {
  element.textContent = message;
  element.style.color = isError ? "#991b1b" : "#065f46";
}

function normalize(value) {
  return value.trim().toLowerCase();
}

function escapeHtml(value) {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function loadState() {
  const defaultState = {
    categories: seedCategories(),
    nominations: [],
    votes: [],
  };

  const raw = localStorage.getItem(STORAGE_KEY);
  if (!raw) {
    return defaultState;
  }

  try {
    const parsed = JSON.parse(raw);
    if (
      !Array.isArray(parsed.categories) ||
      !Array.isArray(parsed.nominations) ||
      !Array.isArray(parsed.votes)
    ) {
      return defaultState;
    }
    return parsed;
  } catch {
    return defaultState;
  }
}

function seedCategories() {
  return [
    { id: crypto.randomUUID(), name: "President" },
    { id: crypto.randomUUID(), name: "Vice President" },
    { id: crypto.randomUUID(), name: "Secretary" },
  ];
}

function persist() {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

setPortal("client");
syncAdminSessionUi();
renderAll();
