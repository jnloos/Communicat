{{-- Deleting asks through the shared x-confirm-modal; only the rename needs a dialog of its own. --}}
<div>
    <x-name-modal name="project-rename" submit="saveProjectTitle" model="form.title"
        :heading="__('groups.rename_project.heading')" :label="__('common.fields.title')" />
</div>
