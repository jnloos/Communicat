# Graph Report - .  (2026-04-14)

## Corpus Check
- Large corpus: 328 files · ~130,481 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder, or use --no-semantic to run AST-only.

## Summary
- 452 nodes · 458 edges · 88 communities detected
- Extraction: 91% EXTRACTED · 9% INFERRED · 0% AMBIGUOUS · INFERRED: 40 edges (avg confidence: 0.79)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_User Auth & Account Management|User Auth & Account Management]]
- [[_COMMUNITY_Project UI & Admin Actions|Project UI & Admin Actions]]
- [[_COMMUNITY_Core App Services & Commands|Core App Services & Commands]]
- [[_COMMUNITY_AI Expert Discussion Engine|AI Expert Discussion Engine]]
- [[_COMMUNITY_Project Collaboration UI|Project Collaboration UI]]
- [[_COMMUNITY_UI Components & Icons|UI Components & Icons]]
- [[_COMMUNITY_Chat Generation Control|Chat Generation Control]]
- [[_COMMUNITY_Project & Settings Models|Project & Settings Models]]
- [[_COMMUNITY_Real-time Event Broadcasting|Real-time Event Broadcasting]]
- [[_COMMUNITY_Settings & Admin Panel|Settings & Admin Panel]]
- [[_COMMUNITY_Contributor Selection Flow|Contributor Selection Flow]]
- [[_COMMUNITY_AI Discussion Core Concepts|AI Discussion Core Concepts]]
- [[_COMMUNITY_Expert CRUD Management|Expert CRUD Management]]
- [[_COMMUNITY_Job Debug & Logging|Job Debug & Logging]]
- [[_COMMUNITY_Service Registration & Markdown|Service Registration & Markdown]]
- [[_COMMUNITY_Concurrent Job Management|Concurrent Job Management]]
- [[_COMMUNITY_Job Log Broadcasting|Job Log Broadcasting]]
- [[_COMMUNITY_Rate-Limited Login|Rate-Limited Login]]
- [[_COMMUNITY_Message Generation Events|Message Generation Events]]
- [[_COMMUNITY_Contributors Change Events|Contributors Change Events]]
- [[_COMMUNITY_Generation Started Events|Generation Started Events]]
- [[_COMMUNITY_Generation Stopped Events|Generation Stopped Events]]
- [[_COMMUNITY_Message Sent Events|Message Sent Events]]
- [[_COMMUNITY_Message Generator Job|Message Generator Job]]
- [[_COMMUNITY_Create Project Livewire|Create Project Livewire]]
- [[_COMMUNITY_Auth View Components|Auth View Components]]
- [[_COMMUNITY_Markdown & Contributor UI|Markdown & Contributor UI]]
- [[_COMMUNITY_Bulk Create Users Command|Bulk Create Users Command]]
- [[_COMMUNITY_Create User Command|Create User Command]]
- [[_COMMUNITY_Init Experts Command|Init Experts Command]]
- [[_COMMUNITY_Generate Message Command|Generate Message Command]]
- [[_COMMUNITY_Build Suite Command|Build Suite Command]]
- [[_COMMUNITY_Markdown Facade|Markdown Facade]]
- [[_COMMUNITY_Email Verify Controller|Email Verify Controller]]
- [[_COMMUNITY_Admin Middleware|Admin Middleware]]
- [[_COMMUNITY_Logout Action|Logout Action]]
- [[_COMMUNITY_Registration Livewire|Registration Livewire]]
- [[_COMMUNITY_Expert List Livewire|Expert List Livewire]]
- [[_COMMUNITY_Debug Channel & Job Events|Debug Channel & Job Events]]
- [[_COMMUNITY_Job Debug Panel Views|Job Debug Panel Views]]
- [[_COMMUNITY_Base Controller|Base Controller]]
- [[_COMMUNITY_Appearance Settings Livewire|Appearance Settings Livewire]]
- [[_COMMUNITY_Experts Page View|Experts Page View]]
- [[_COMMUNITY_Home & Create Project Views|Home & Create Project Views]]
- [[_COMMUNITY_Project Chat Page View|Project Chat Page View]]
- [[_COMMUNITY_Expert Editor Views|Expert Editor Views]]
- [[_COMMUNITY_Select Contributors View|Select Contributors View]]
- [[_COMMUNITY_Job Debug Panel View|Job Debug Panel View]]
- [[_COMMUNITY_Markdown Facade & Service|Markdown Facade & Service]]
- [[_COMMUNITY_App Logo Components|App Logo Components]]
- [[_COMMUNITY_Auth Status Components|Auth Status Components]]
- [[_COMMUNITY_Contributor Avatar Components|Contributor Avatar Components]]
- [[_COMMUNITY_Frontend JS Entry|Frontend JS Entry]]
- [[_COMMUNITY_Action Message View|Action Message View]]
- [[_COMMUNITY_App Logo Icon View|App Logo Icon View]]
- [[_COMMUNITY_Auth Header View|Auth Header View]]
- [[_COMMUNITY_Auth Session Status View|Auth Session Status View]]
- [[_COMMUNITY_Placeholder Pattern View|Placeholder Pattern View]]
- [[_COMMUNITY_Confirm Modal View|Confirm Modal View]]
- [[_COMMUNITY_App Logo View|App Logo View]]
- [[_COMMUNITY_Contributors Avatar View|Contributors Avatar View]]
- [[_COMMUNITY_Contributors Card View|Contributors Card View]]
- [[_COMMUNITY_App Layout View|App Layout View]]
- [[_COMMUNITY_Auth Layout View|Auth Layout View]]
- [[_COMMUNITY_Contributor Group View|Contributor Group View]]
- [[_COMMUNITY_Chat Message View|Chat Message View]]
- [[_COMMUNITY_Welcome Message View|Welcome Message View]]
- [[_COMMUNITY_Settings Layout View|Settings Layout View]]
- [[_COMMUNITY_Book Icon View|Book Icon View]]
- [[_COMMUNITY_Chevrons Icon View|Chevrons Icon View]]
- [[_COMMUNITY_Folder Git Icon View|Folder Git Icon View]]
- [[_COMMUNITY_Layout Grid Icon View|Layout Grid Icon View]]
- [[_COMMUNITY_Nav Group View|Nav Group View]]
- [[_COMMUNITY_Flux Composer View|Flux Composer View]]
- [[_COMMUNITY_Login View|Login View]]
- [[_COMMUNITY_Register View|Register View]]
- [[_COMMUNITY_Create Project View|Create Project View]]
- [[_COMMUNITY_Control Chat View|Control Chat View]]
- [[_COMMUNITY_Delete User Form View|Delete User Form View]]
- [[_COMMUNITY_Settings Heading Partial|Settings Heading Partial]]
- [[_COMMUNITY_Head Partial|Head Partial]]
- [[_COMMUNITY_Expert Summaries Prompt|Expert Summaries Prompt]]
- [[_COMMUNITY_Next Message Prompt|Next Message Prompt]]
- [[_COMMUNITY_Placeholder Component|Placeholder Component]]
- [[_COMMUNITY_Project Welcome Component|Project Welcome Component]]
- [[_COMMUNITY_Flux Composer Component|Flux Composer Component]]
- [[_COMMUNITY_Verify Email View|Verify Email View]]
- [[_COMMUNITY_Project README|Project README]]

## God Nodes (most connected - your core abstractions)
1. `Project` - 29 edges
2. `Message` - 17 edges
3. `ProjectChat` - 14 edges
4. `ControlChat` - 13 edges
5. `Assistant` - 12 edges
6. `Expert` - 11 edges
7. `User` - 11 edges
8. `Project Chat Blade View` - 11 edges
9. `SelectContributors` - 10 edges
10. `Users` - 10 edges

## Surprising Connections (you probably didn't know these)
- `Communicat Application` --rationale_for--> `Requirements Analysis Discussion (AI-driven)`  [INFERRED]
  README.md → resources/views/prompts/multiple/next-message.blade.php
- `Project` --semantically_similar_to--> `Setting`  [AMBIGUOUS] [semantically similar]
  app/Models/Project.php → app/Models/Setting.php
- `Laravel Echo WebSocket Client` --references--> `Private Projects Channel`  [INFERRED]
  resources/js/app.js → app/Events/MessageGenerated.php
- `Head Partial (HTML Head)` --references--> `Communicat Application`  [EXTRACTED]
  resources/views/partials/head.blade.php → README.md
- `ProjectChat` --semantically_similar_to--> `ControlChat`  [INFERRED] [semantically similar]
  app/Livewire/Projects/ProjectChat.php → app/Livewire/Projects/ControlChat.php

## Hyperedges (group relationships)
- **Authentication Flow (Login, Register, VerifyEmail, Logout)** — login_livewire, register_livewire, verifyemail_livewire, logout_action, verifyemailcontroller [EXTRACTED 0.95]
- **Message Generation Pipeline (Command, Job, Assistant)** — genmessage_command, messagegenerator_job, assistant_service, projectjob_base [INFERRED 0.90]
- **Project Contributor Management (SelectContributors, Experts, Users)** — selectcontributors_livewire, model_expert, model_user, event_contributorschanged [EXTRACTED 0.88]
- **AI Message Generation Pipeline** — assistant_assistant, openaiclient_openaiclient, promptbuilder_promptbuilder, expert_expert, project_project [EXTRACTED 0.95]
- **Project Chat Realtime Communication Flow** — projectchat_projectchat, controlchat_controlchat, message_message, project_project [INFERRED 0.88]
- **User Management and Admin Authorization Flow** — users_users, user_user, authserviceprovider_authserviceprovider [INFERRED 0.82]
- **Project-scoped Real-time Event Broadcasting Flow** — events_messagegenerated, events_generationstarted, events_generationstopped, events_messagesent, events_contributorschanged, channel_projectsprivate [EXTRACTED 0.95]
- **Page View + App Layout + Livewire Component Pattern** — views_project, components_layoutsapp, livewire_projectchat [EXTRACTED 1.00]
- **Contributor Display Component Hierarchy** — components_contributorscard, components_contributorsavatar, livewire_expertlist [INFERRED 0.75]
- **Auth Layout Variant Family** — layouts_auth, layouts_auth_simple, layouts_auth_card, layouts_auth_split [EXTRACTED 0.95]
- **Custom Lucide-Based Flux Icon Set** — flux_icon_book_open_text, flux_icon_chevrons_up_down, flux_icon_folder_git_2, flux_icon_layout_grid [EXTRACTED 0.95]
- **Livewire Authentication View Set** — livewire_auth_login, livewire_auth_register, livewire_auth_verify_email [INFERRED 0.90]
- **Project Chat Orchestration (Chat + Contributors + Edit)** — project_chat_view, select_contributors_view, edit_project_view, control_chat_view [EXTRACTED 0.95]
- **Settings Section Pattern (Heading Partial + Layout Component + Settings Views)** — settings_heading_partial, component_settings_layout, appearance_settings_view, profile_settings_view, users_settings_view [EXTRACTED 0.90]
- **AI Expert Discussion Prompt System (Summaries + Next Message + Importance)** — expert_summaries_prompt, next_message_prompt, concept_importance_score, concept_expert_summary [EXTRACTED 0.92]

## Communities

### Community 0 - "User Auth & Account Management"
Cohesion: 0.06
Nodes (9): AuthServiceProvider, DeleteUserForm, logout, needsConfirmation(), Profile, sendVerification, User, Users (+1 more)

### Community 1 - "Project UI & Admin Actions"
Cohesion: 0.07
Nodes (10): EditProject, needsConfirmation(, openCreate, openEdit({{ $user->id }}), partials.settings-heading, ProjectChat, projects.control-chat, projects.edit-project (+2 more)

### Community 2 - "Core App Services & Commands"
Cohesion: 0.09
Nodes (29): Assistant Service, BuildSuite Command, BulkCreateUsers Command, Cache Lock Pattern for Concurrent Job Prevention, Base Controller, CreateProject Livewire Component, CreateUser Command, EditProject Livewire Component (+21 more)

### Community 3 - "AI Expert Discussion Engine"
Cohesion: 0.09
Nodes (5): Assistant, Expert, OpenAIClient, PromptBuilder, Summary

### Community 4 - "Project Collaboration UI"
Cohesion: 0.09
Nodes (25): Contributors Card Blade Component, Projects Chat Message Component, Projects Contributor Group Component, Expert Avatar Upload, manage-contributors Authorization Policy, manage-project Authorization Policy, Scroll-back Pagination for Chat Messages, Control Chat Blade View (+17 more)

### Community 5 - "UI Components & Icons"
Cohesion: 0.13
Nodes (18): App Logo Component, App Logo Icon Component, Confirm Modal Component, debug.job-debug-panel, Flux Icon: Book Open Text (Lucide), Flux Icon: Chevrons Up Down (Lucide), Flux Icon: Folder Git 2 (Lucide), Flux Icon: Layout Grid (Lucide) (+10 more)

### Community 6 - "Chat Generation Control"
Cohesion: 0.1
Nodes (2): ControlChat, Message

### Community 7 - "Project & Settings Models"
Cohesion: 0.16
Nodes (2): Project, Setting

### Community 8 - "Real-time Event Broadcasting"
Cohesion: 0.13
Nodes (18): Private Projects Channel, Action Message Component, Confirm Modal Component, App Layout Component, ContributorsChanged Event, GenerationStarted Event, GenerationStopped Event, MessageGenerated Event (+10 more)

### Community 9 - "Settings & Admin Panel"
Cohesion: 0.19
Nodes (13): Appearance Settings Blade View, Settings Layout Component, Administrator Role (is_admin flag), Dark / Light / System Theme Appearance, Email Verification for Users, Delete User Form Blade View, Profile Settings Blade View, Settings Heading Partial (+5 more)

### Community 10 - "Contributor Selection Flow"
Cohesion: 0.2
Nodes (1): SelectContributors

### Community 11 - "AI Discussion Core Concepts"
Cohesion: 0.24
Nodes (11): Communicat Application, Expert Summary (Living Brief per Expert), Expert Contribution Importance Score, Memory Reduction / Message Frequency Setting, Requirements Analysis Discussion (AI-driven), Create Project Blade View, Edit Project Blade View, Expert Summaries LLM Prompt Template (+3 more)

### Community 12 - "Expert CRUD Management"
Cohesion: 0.33
Nodes (1): ExpertEditor

### Community 13 - "Job Debug & Logging"
Cohesion: 0.22
Nodes (2): JobDebugPanel, JobLog

### Community 14 - "Service Registration & Markdown"
Cohesion: 0.25
Nodes (2): AppServiceProvider, MarkdownParser

### Community 15 - "Concurrent Job Management"
Cohesion: 0.33
Nodes (1): ProjectJob

### Community 16 - "Job Log Broadcasting"
Cohesion: 0.33
Nodes (1): JobLogged

### Community 17 - "Rate-Limited Login"
Cohesion: 0.7
Nodes (1): Login

### Community 18 - "Message Generation Events"
Cohesion: 0.4
Nodes (1): MessageGenerated

### Community 19 - "Contributors Change Events"
Cohesion: 0.4
Nodes (1): ContributorsChanged

### Community 20 - "Generation Started Events"
Cohesion: 0.4
Nodes (1): GenerationStarted

### Community 21 - "Generation Stopped Events"
Cohesion: 0.4
Nodes (1): GenerationStopped

### Community 22 - "Message Sent Events"
Cohesion: 0.4
Nodes (1): MessageSent

### Community 23 - "Message Generator Job"
Cohesion: 0.5
Nodes (1): MessageGenerator

### Community 24 - "Create Project Livewire"
Cohesion: 0.5
Nodes (1): CreateProject

### Community 25 - "Auth View Components"
Cohesion: 0.83
Nodes (4): Auth Header Component, Auth Session Status Component, Livewire Auth Login View, Livewire Auth Register View

### Community 26 - "Markdown & Contributor UI"
Cohesion: 0.5
Nodes (4): Contributors Avatar Component, Markdown Facade, Chat Message Component, Contributor Group Component

### Community 27 - "Bulk Create Users Command"
Cohesion: 0.67
Nodes (1): BulkCreateUsers

### Community 28 - "Create User Command"
Cohesion: 0.67
Nodes (1): CreateUser

### Community 29 - "Init Experts Command"
Cohesion: 0.67
Nodes (1): InitExperts

### Community 30 - "Generate Message Command"
Cohesion: 0.67
Nodes (1): GenMessage

### Community 31 - "Build Suite Command"
Cohesion: 0.67
Nodes (1): BuildSuite

### Community 32 - "Markdown Facade"
Cohesion: 0.67
Nodes (1): Markdown

### Community 33 - "Email Verify Controller"
Cohesion: 0.67
Nodes (1): VerifyEmailController

### Community 34 - "Admin Middleware"
Cohesion: 0.67
Nodes (1): EnsureUserIsAdmin

### Community 35 - "Logout Action"
Cohesion: 0.67
Nodes (1): Logout

### Community 36 - "Registration Livewire"
Cohesion: 0.67
Nodes (1): Register

### Community 37 - "Expert List Livewire"
Cohesion: 0.67
Nodes (1): ExpertList

### Community 38 - "Debug Channel & Job Events"
Cohesion: 0.67
Nodes (3): Private Debug Channel, JobLogged Event, JobLog Model

### Community 39 - "Job Debug Panel Views"
Cohesion: 0.67
Nodes (3): Job Log (background job tracking), Job Debug Panel Blade View, Livewire open Action (Job Debug Panel)

### Community 40 - "Base Controller"
Cohesion: 1.0
Nodes (1): Controller

### Community 41 - "Appearance Settings Livewire"
Cohesion: 1.0
Nodes (1): Appearance

### Community 42 - "Experts Page View"
Cohesion: 1.0
Nodes (1): experts.expert-list

### Community 43 - "Home & Create Project Views"
Cohesion: 1.0
Nodes (1): projects.create-project

### Community 44 - "Project Chat Page View"
Cohesion: 1.0
Nodes (1): projects.project-chat

### Community 45 - "Expert Editor Views"
Cohesion: 1.0
Nodes (1): experts.expert-editor

### Community 46 - "Select Contributors View"
Cohesion: 1.0
Nodes (1): {{ $active ? 

### Community 47 - "Job Debug Panel View"
Cohesion: 1.0
Nodes (1): open

### Community 48 - "Markdown Facade & Service"
Cohesion: 1.0
Nodes (2): Markdown Facade, MarkdownParser Service

### Community 49 - "App Logo Components"
Cohesion: 1.0
Nodes (2): App Logo Component, App Logo Icon Component

### Community 50 - "Auth Status Components"
Cohesion: 1.0
Nodes (2): Auth Header Component, Auth Session Status Component

### Community 51 - "Contributor Avatar Components"
Cohesion: 1.0
Nodes (2): Contributors Avatar Component, Contributors Card Component

### Community 52 - "Frontend JS Entry"
Cohesion: 1.0
Nodes (0): 

### Community 53 - "Action Message View"
Cohesion: 1.0
Nodes (0): 

### Community 54 - "App Logo Icon View"
Cohesion: 1.0
Nodes (0): 

### Community 55 - "Auth Header View"
Cohesion: 1.0
Nodes (0): 

### Community 56 - "Auth Session Status View"
Cohesion: 1.0
Nodes (0): 

### Community 57 - "Placeholder Pattern View"
Cohesion: 1.0
Nodes (0): 

### Community 58 - "Confirm Modal View"
Cohesion: 1.0
Nodes (0): 

### Community 59 - "App Logo View"
Cohesion: 1.0
Nodes (0): 

### Community 60 - "Contributors Avatar View"
Cohesion: 1.0
Nodes (0): 

### Community 61 - "Contributors Card View"
Cohesion: 1.0
Nodes (0): 

### Community 62 - "App Layout View"
Cohesion: 1.0
Nodes (0): 

### Community 63 - "Auth Layout View"
Cohesion: 1.0
Nodes (0): 

### Community 64 - "Contributor Group View"
Cohesion: 1.0
Nodes (0): 

### Community 65 - "Chat Message View"
Cohesion: 1.0
Nodes (0): 

### Community 66 - "Welcome Message View"
Cohesion: 1.0
Nodes (0): 

### Community 67 - "Settings Layout View"
Cohesion: 1.0
Nodes (0): 

### Community 68 - "Book Icon View"
Cohesion: 1.0
Nodes (0): 

### Community 69 - "Chevrons Icon View"
Cohesion: 1.0
Nodes (0): 

### Community 70 - "Folder Git Icon View"
Cohesion: 1.0
Nodes (0): 

### Community 71 - "Layout Grid Icon View"
Cohesion: 1.0
Nodes (0): 

### Community 72 - "Nav Group View"
Cohesion: 1.0
Nodes (0): 

### Community 73 - "Flux Composer View"
Cohesion: 1.0
Nodes (0): 

### Community 74 - "Login View"
Cohesion: 1.0
Nodes (0): 

### Community 75 - "Register View"
Cohesion: 1.0
Nodes (0): 

### Community 76 - "Create Project View"
Cohesion: 1.0
Nodes (0): 

### Community 77 - "Control Chat View"
Cohesion: 1.0
Nodes (0): 

### Community 78 - "Delete User Form View"
Cohesion: 1.0
Nodes (0): 

### Community 79 - "Settings Heading Partial"
Cohesion: 1.0
Nodes (0): 

### Community 80 - "Head Partial"
Cohesion: 1.0
Nodes (0): 

### Community 81 - "Expert Summaries Prompt"
Cohesion: 1.0
Nodes (0): 

### Community 82 - "Next Message Prompt"
Cohesion: 1.0
Nodes (0): 

### Community 83 - "Placeholder Component"
Cohesion: 1.0
Nodes (1): Placeholder Pattern Component

### Community 84 - "Project Welcome Component"
Cohesion: 1.0
Nodes (1): Project Welcome Message Component

### Community 85 - "Flux Composer Component"
Cohesion: 1.0
Nodes (1): Flux Composer Component

### Community 86 - "Verify Email View"
Cohesion: 1.0
Nodes (1): Livewire Auth Verify Email View

### Community 87 - "Project README"
Cohesion: 1.0
Nodes (1): Communicat README

## Ambiguous Edges - Review These
- `Project` → `Setting`  [AMBIGUOUS]
  app/Models/Project.php · relation: semantically_similar_to

## Knowledge Gaps
- **80 isolated node(s):** `Controller`, `Appearance`, `experts.expert-list`, `projects.create-project`, `projects.project-chat` (+75 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **Thin community `Base Controller`** (2 nodes): `Controller.php`, `Controller`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Appearance Settings Livewire`** (2 nodes): `Appearance.php`, `Appearance`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Experts Page View`** (2 nodes): `experts.expert-list`, `experts.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Home & Create Project Views`** (2 nodes): `projects.create-project`, `index.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Project Chat Page View`** (2 nodes): `projects.project-chat`, `project.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Expert Editor Views`** (2 nodes): `experts.expert-editor`, `expert-list.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Select Contributors View`** (2 nodes): `{{ $active ? `, `select-contributors.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Job Debug Panel View`** (2 nodes): `open`, `job-debug-panel.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Markdown Facade & Service`** (2 nodes): `Markdown Facade`, `MarkdownParser Service`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `App Logo Components`** (2 nodes): `App Logo Component`, `App Logo Icon Component`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Auth Status Components`** (2 nodes): `Auth Header Component`, `Auth Session Status Component`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Contributor Avatar Components`** (2 nodes): `Contributors Avatar Component`, `Contributors Card Component`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Frontend JS Entry`** (1 nodes): `app.js`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Action Message View`** (1 nodes): `action-message.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `App Logo Icon View`** (1 nodes): `app-logo-icon.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Auth Header View`** (1 nodes): `auth-header.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Auth Session Status View`** (1 nodes): `auth-session-status.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Placeholder Pattern View`** (1 nodes): `placeholder-pattern.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Confirm Modal View`** (1 nodes): `confirm-modal.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `App Logo View`** (1 nodes): `app-logo.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Contributors Avatar View`** (1 nodes): `contributors-avatar.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Contributors Card View`** (1 nodes): `contributors-card.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `App Layout View`** (1 nodes): `app.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Auth Layout View`** (1 nodes): `auth.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Contributor Group View`** (1 nodes): `contributor-group.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Chat Message View`** (1 nodes): `chat-message.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Welcome Message View`** (1 nodes): `welcome-message.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Settings Layout View`** (1 nodes): `layout.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Book Icon View`** (1 nodes): `book-open-text.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Chevrons Icon View`** (1 nodes): `chevrons-up-down.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Folder Git Icon View`** (1 nodes): `folder-git-2.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Layout Grid Icon View`** (1 nodes): `layout-grid.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Nav Group View`** (1 nodes): `group.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Flux Composer View`** (1 nodes): `index.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Login View`** (1 nodes): `login.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Register View`** (1 nodes): `register.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Create Project View`** (1 nodes): `create-project.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Control Chat View`** (1 nodes): `control-chat.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Delete User Form View`** (1 nodes): `delete-user-form.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Settings Heading Partial`** (1 nodes): `settings-heading.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Head Partial`** (1 nodes): `head.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Expert Summaries Prompt`** (1 nodes): `expert-summaries.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Next Message Prompt`** (1 nodes): `next-message.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Placeholder Component`** (1 nodes): `Placeholder Pattern Component`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Project Welcome Component`** (1 nodes): `Project Welcome Message Component`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Flux Composer Component`** (1 nodes): `Flux Composer Component`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Verify Email View`** (1 nodes): `Livewire Auth Verify Email View`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Project README`** (1 nodes): `Communicat README`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `Project` and `Setting`?**
  _Edge tagged AMBIGUOUS (relation: semantically_similar_to) - confidence is low._
- **Why does `Project` connect `Project & Settings Models` to `User Auth & Account Management`, `Project UI & Admin Actions`, `AI Expert Discussion Engine`, `Chat Generation Control`, `Job Debug & Logging`?**
  _High betweenness centrality (0.058) - this node is a cross-community bridge._
- **Why does `ProjectChat` connect `Project UI & Admin Actions` to `User Auth & Account Management`, `Chat Generation Control`, `Project & Settings Models`?**
  _High betweenness centrality (0.042) - this node is a cross-community bridge._
- **Why does `needsConfirmation(` connect `Project UI & Admin Actions` to `Expert CRUD Management`?**
  _High betweenness centrality (0.041) - this node is a cross-community bridge._
- **Are the 3 inferred relationships involving `Message` (e.g. with `ProjectChat` and `Expert`) actually correct?**
  _`Message` has 3 INFERRED edges - model-reasoned connections that need verification._
- **Are the 3 inferred relationships involving `ProjectChat` (e.g. with `needsConfirmation()` and `Message`) actually correct?**
  _`ProjectChat` has 3 INFERRED edges - model-reasoned connections that need verification._
- **What connects `Controller`, `Appearance`, `experts.expert-list` to the rest of the system?**
  _80 weakly-connected nodes found - possible documentation gaps or missing edges._