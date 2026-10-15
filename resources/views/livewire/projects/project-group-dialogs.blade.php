<div>
    <x-name-modal name="project-group-create" submit="createGroup" model="form.name"
        :heading="__('groups.create.heading')" :subheading="__('groups.create.subheading')"
        :label="__('groups.fields.name')" :submit-label="__('groups.create.submit')" />

    <x-name-modal name="project-group-rename" submit="saveGroupName" model="form.name"
        :heading="__('groups.rename_group.heading')" :label="__('groups.fields.name')" />
</div>
