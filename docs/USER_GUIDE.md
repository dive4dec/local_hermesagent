# Hermes Agent — User Guide

*A guide for site administrators and educators using the local_hermesagent Moodle plugin.*

---

## Why Hermes Agent?

Large language models (LLMs) are powerful tools, but accessing them outside your Learning Management System breaks the educational workflow. Students and teachers need AI assistance that integrates seamlessly into the platform they already use every day. At the same time, institutions need to control costs, enforce security policies, and maintain data governance standards like GDPR.

The **Hermes Agent** plugin bridges Moodle with the [Hermes AI Agent framework](https://github.com/nousresearch/hermes), giving you AI-powered assistance directly inside your LMS. It provides:

- **Integrated workflow** — AI assistance without leaving Moodle.
- **Cost control** — Administrators configure per-user API budget limits, so usage stays within institutional budgets.
- **Security** — During the pilot phase, access is restricted to administrators, giving institutions full control over who interacts with the AI.
- **Data governance** — Conversations are stored in the Moodle database with full GDPR-compliant export and deletion capabilities.
- **Persistent skills** — The AI learns from your institutional context and retains useful skills across sessions.

---

## Who Is This For?

This plugin is designed for:

- **Faculty** researching or preparing teaching materials who need AI to help draft content, generate examples, or explore new ideas.
- **Educators** exploring AI-assisted pedagogy and looking for practical ways to incorporate AI into their teaching practice.
- **LMS administrators** piloting AI integration and wanting to understand what it looks like before rolling it out more broadly.
- **Researchers** needing AI support within an institutional environment, where data governance and compliance matter.

---

## What Can You Do? (Use Cases)

### 1. Course Content Assistance

Use Hermes Agent to help develop and refine course materials.

- Ask for **explanations of complex topics** tailored to specific audience levels.
- **Generate practice questions** for quizzes, assignments, or in-class activities.
- Get **step-by-step math solutions** with beautifully rendered equations.

> **Example prompt:** *"Explain Euler's formula with examples suitable for undergraduate engineering students."*

### 2. Administrative Tasks

Streamline common academic administrative work.

- **Draft assessment rubrics** for assignments, projects, or presentations.
- **Generate learning objectives** from course outcomes or accreditation standards.
- **Create quiz questions** with multiple-choice, short-answer, or essay formats.

> **Example prompt:** *"Create a 5-point rubric for evaluating student research papers, with criteria for thesis clarity, evidence, structure, and academic writing."*

### 3. Research Support

Get AI-powered assistance for your research workflow.

- Receive **literature search guidance** including suggested databases, keywords, and search strategies.
- Get explanations of **statistical analysis methods** and interpretations.
- Consult on **research methodology** choices and best practices.

> **Example prompt:** *"What are common pitfalls in longitudinal educational research, and how can I design my study to avoid them?"*

### 4. Technical Help

Get immediate support for Moodle-specific questions.

- **Moodle troubleshooting** for issues you encounter in day-to-day use.
- **Plugin configuration questions** including installation and compatibility.
- **Workflow optimization** advice for managing courses, grades, or user roles.

> **Example prompt:** *"How do I set up grade categories for group work so that each group member receives the same grade?"*

---

## Getting Started

### Step 1: Access the Chat

1. Log in to Moodle with an administrator account.
2. Navigate to **Site administration > Plugins > Local plugins > Hermes Agent**.
3. Click the **"Open Hermes Chat"** button.

### Step 2: Start a Conversation

- The chat opens with a new conversation ready to go.
- Type your message in the **input field at the bottom** of the chat window.
- Press **Enter** or click the **Send** button to submit.
- The AI responds in real-time — you'll see the reply stream in as it's generated.

### Step 3: Working with Conversations

Once you've been chatting for a while, you'll want to organize your conversations:

- **New conversation** — Click the **"+"** button to start a fresh conversation, clearing the current context.
- **View history** — Click the **sidebar icon** to toggle the conversation list and see all your past conversations.
- **Rename** — Click the **pencil icon** on any conversation to give it a descriptive name (e.g., "Physics Lesson Planning" instead of "New Conversation").
- **Delete** — Click the **trash icon** to permanently remove a conversation and all its messages.
- **Search** — Use the **sidebar search** field to quickly find past conversations by name or content.

---

## Formatting Your Messages

### Text Input

- **Plain text** works for most queries — just type naturally.
- Use **code blocks** (triple backticks `` ``` ``) when sharing code snippets or configuration examples.

### Math Equations

When you need to include mathematical notation:

- Use `\[ ... \]` for **display math** (centered, on its own line).
- Use `$ ... $` for **inline math** (within a sentence).

The AI supports LaTeX-style math notation, and equations are rendered with professional formatting using MathJax.

### Response Formatting

The AI responds in rich markdown, including:

- Headings, bold/italic text, and lists
- Code blocks with syntax highlighting
- Tables for structured data
- Math equations rendered with LaTeX formatting
- Links and other standard markdown features

---

## Tips & Best Practices

- **Be specific in your prompts.** The more context and detail you provide, the more useful and targeted the AI's response will be. Instead of "explain calculus," try "explain limits and derivatives to first-year students with real-world examples."

- **Use new conversations for different topics.** Each conversation maintains context from earlier messages. Starting fresh helps the AI stay focused and avoids confusion from unrelated prior messages.

- **Ask for different explanation levels.** The AI can adapt its responses — specify whether you want a beginner-friendly overview, an intermediate deep-dive, or expert-level detail.

- **Math equations render automatically.** When the AI includes mathematical formulas, they're displayed with proper LaTeX formatting. No special action is needed on your part.

- **Review tool requests carefully.** If the AI proposes performing an action on your Moodle instance (like querying the database), you'll see a confirmation prompt. Review the request before approving.

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Chat won't load | Check that the ACP Bridge is running on the admin settings page. If it's stopped, start it from there. |
| No response from the AI | Verify that your API key has available budget. Contact your institution's admin if you suspect a budget issue. |
| Math equations not rendering | Hard refresh the page (**Ctrl+Shift+R** on Windows/Linux, **Cmd+Shift+R** on Mac) to clear the browser cache. |
| Connection error message | Check the bridge health status on the admin settings page. If the bridge is down, restart it. |
| Access denied | This plugin is currently admin-only during the pilot phase. Contact your site administrator for access. |

---

## Frequently Asked Questions

**Q: Can students use this?**
A: Currently, the plugin is restricted to administrators during the pilot phase. Wider access may be enabled based on the results of this pilot.

**Q: Is my conversation stored?**
A: Yes, all conversations and messages are stored in the Moodle database. You can delete conversations and their messages at any time using the trash icon.

**Q: What AI model is used?**
A: The AI model is configured by your institution's administrator. It typically uses a research-grade model accessed through the Hermes framework.

**Q: Can I export my conversations?**
A: Yes. Conversations can be exported for GDPR compliance through Moodle's admin panel. Site administrators can export personal data on behalf of users.

**Q: Is there an API budget limit?**
A: Yes. Administrators configure per-user daily budget limits through the Hermes admin interface to manage costs and prevent excessive usage.

**Q: The AI suggested an action — what should I do?**
A: When the AI proposes using a tool (such as running a database query), you'll see a confirmation dialog. Review the requested action carefully and click **Approve** or **Reject**. Only SELECT queries run automatically; any action that modifies data requires your explicit approval.

**Q: Can I rename old conversations?**
A: Yes. Click the pencil icon next to any conversation in the sidebar to rename it. This helps you organize and find past conversations more easily.

**Q: What happens when I delete a conversation?**
A: The conversation and all its messages are permanently removed from the Moodle database. This action cannot be undone.

---

*For technical documentation and development details, see the plugin's developer README in the `docs/` directory.*
